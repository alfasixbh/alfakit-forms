<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\CreateForm;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\ListForms;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\ViewForm;
use AlfaSix\AlfakitForms\Resources\FormResource\RelationManagers\SubmissionsRelationManager;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\inertiaGet;
use function AlfaSix\AlfakitForms\Tests\publicForm;
use function AlfaSix\AlfakitForms\Tests\submitForm;
use function AlfaSix\AlfakitForms\Tests\team;
use function AlfaSix\AlfakitForms\Tests\user;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * Two teams; Ada belongs to Alpha only.
 *
 * @return array{alpha: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, beta: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, mine: Form, theirs: Form}
 */
function formsTeams(): array
{
    $alpha = team('Alpha');
    $beta = team('Beta');
    $ada = user('Ada');
    $ada->teams()->attach($alpha);
    actingAs($ada);

    $mine = contactForm(['title' => 'Alpha form']);
    $mine->tenant()->associate($alpha)->save();
    $theirs = contactForm(['title' => 'Beta form']);
    $theirs->tenant()->associate($beta)->save();

    return compact('alpha', 'beta', 'mine', 'theirs');
}

it('lists only the forms of the current tenant', function (): void {
    ['alpha' => $alpha, 'mine' => $mine, 'theirs' => $theirs] = formsTeams();

    alfakit()->testPage(ListForms::class, ['tenant' => $alpha], panel: 'forms-team')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    inertiaGet('/forms-team/alpha/forms/'.$theirs->getKey())->assertNotFound();
    inertiaGet('/forms-team/alpha/forms/'.$theirs->getKey().'/edit')->assertNotFound();
    inertiaGet('/forms-team/beta/forms')->assertNotFound();
});

it('creates forms in the current tenant', function (): void {
    ['alpha' => $alpha] = formsTeams();

    alfakit()->testPage(CreateForm::class, ['tenant' => $alpha], panel: 'forms-team')
        ->fillForm(['title' => 'Team survey', 'slug' => 'team-survey'])
        ->call('create')
        ->assertHasNoFormErrors();

    $form = Form::query()->where('slug', 'team-survey')->sole();

    expect($form->tenant_type)->toBe($alpha->getMorphClass())
        ->and((string) $form->tenant_id)->toBe((string) $alpha->getKey());
});

it('stores the form\'s tenant with each public submission', function (): void {
    ['beta' => $beta, 'theirs' => $theirs] = formsTeams();

    // The public is never a member of the tenant: a published form is open to everyone.
    auth()->logout();

    submitForm(publicForm($theirs->slug)['token'], ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales'])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->tenant_type)->toBe($beta->getMorphClass())
        ->and((string) $submission->tenant_id)->toBe((string) $beta->getKey());
});

it('keeps the submissions of another tenant\'s form out of reach', function (): void {
    ['alpha' => $alpha, 'mine' => $mine, 'theirs' => $theirs] = formsTeams();
    $ours = $mine->submissions()->create(['data' => ['your_name' => 'Ann']]);
    $foreign = $theirs->submissions()->create(['data' => ['your_name' => 'Eve']]);

    alfakit()->testPage(ViewForm::class, ['record' => $mine, 'tenant' => $alpha], panel: 'forms-team')
        ->relationManager(SubmissionsRelationManager::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$foreign]);

    // Posting the other tenant's submission to this form's manager finds nothing.
    $token = inertiaGet('/forms-team/alpha/forms/'.$mine->getKey())->json('props.page.token');

    AlfaSix\AlfakitForms\Tests\inertiaPost('/forms-team/_alfakit/actions', [
        'token' => $token,
        'component' => 'relationManager:'.SubmissionsRelationManager::class,
        'action' => 'delete',
        'record' => $foreign->getKey(),
    ])->assertNotFound();

    AlfaSix\AlfakitForms\Tests\inertiaPost('/forms-team/_alfakit/actions', [
        'token' => $token,
        'component' => 'relationManager:'.SubmissionsRelationManager::class,
        'action' => 'delete',
        'record' => $ours->getKey(),
    ])->assertRedirect();

    expect(FormSubmission::query()->whereKey($foreign->getKey())->exists())->toBeTrue()
        ->and(FormSubmission::query()->whereKey($ours->getKey())->exists())->toBeFalse();
});

it('follows the form policy when the plugin has no authorize() closure', function (): void {
    actingAs(user());
    $form = contactForm();
    $submission = $form->submissions()->create(['data' => ['your_name' => 'Ann']]);

    Gate::policy(Form::class, ReadOnlyFormPolicy::class);

    inertiaGet('/forms-admin/forms')->assertOk();
    inertiaGet('/forms-admin/forms/'.$form->getKey())->assertOk();
    inertiaGet('/forms-admin/forms/'.$form->getKey().'/edit')->assertForbidden();
    inertiaGet('/forms-admin/forms/create')->assertForbidden();

    // Without `update` on the form, its submissions are read only.
    alfakit()->testPage(ViewForm::class, ['record' => $form], panel: 'forms-admin')
        ->relationManager(SubmissionsRelationManager::class)
        ->assertCanSeeTableRecords([$submission])
        ->assertTableActionHidden(DeleteAction::class, $submission)
        ->assertTableActionHidden('markAsSpam', $submission);
});

it('rejects guests in the panel', function (): void {
    $form = contactForm();

    inertiaGet('/forms-admin/forms')->assertRedirect();
    inertiaGet('/forms-admin/forms/'.$form->getKey())->assertRedirect();
});

it('never calls a user-typed authorize() closure for a guest', function (): void {
    // The documented `fn (User $user)` callback: global search on the guest login page asks
    // the resource whether it can be searched, which used to call it with null (a 500).
    $plugin = alfakit()->getPanel('forms-admin')->getPlugin(AlfaSix\AlfakitForms\FormsPlugin::ID)
        ->authorize(fn (AlfaSix\AlfakitForms\Tests\Fixtures\Models\FormsUser $user): bool => $user->name === 'Ada Admin');

    inertiaGet('/forms-admin/login')->assertOk();

    alfakit()->setCurrentPanel(alfakit()->getPanel('forms-admin'));

    expect($plugin->hasAuthorization())->toBeTrue()
        ->and($plugin->isAuthorized())->toBeFalse();

    actingAs(user());

    expect($plugin->isAuthorized())->toBeTrue();
});

class ReadOnlyFormPolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Form $form): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return false;
    }

    public function update(mixed $user, Form $form): bool
    {
        return false;
    }

    public function delete(mixed $user, Form $form): bool
    {
        return false;
    }
}
