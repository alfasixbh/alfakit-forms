<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\ReplicateAction;
use AlfaSix\AlfakitForms\FormsPlugin;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\CreateForm;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\EditForm;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\ListForms;
use AlfaSix\AlfakitForms\Support\BuilderBlocks;
use AlfaSix\AlfakitForms\Widgets\FormStatsWidget;

use function AlfaSix\AlfakitForms\Tests\block;
use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\inertiaGet;
use function AlfaSix\AlfakitForms\Tests\user;
use function Pest\Laravel\actingAs;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    actingAs(user());
});

it('registers the forms resource with the plugin', function (): void {
    $page = inertiaGet('/forms-admin/forms')->assertOk()->json('props.page');

    expect($page['title'])->toBe('Forms')
        ->and(alfakit()->getPanel('forms-admin')->hasPlugin(FormsPlugin::ID))->toBeTrue();
});

it('builds a form with the visual builder', function (): void {
    alfakit()->testPage(CreateForm::class, panel: 'forms-admin')
        ->fillForm([
            'title' => 'Job application',
            'fields' => [
                block('text', ['label' => 'Full name', 'required' => true, 'width' => 'half']),
                block('email', ['label' => 'Email address', 'required' => true, 'width' => 'half']),
                block('page_break', ['label' => 'Experience']),
                block('radio', ['label' => 'Seniority', 'options' => [['option' => 'Junior'], ['option' => 'Senior']]]),
                block('textarea', ['label' => 'Tell us about a project', 'conditions' => [['field' => 'seniority', 'operator' => 'equals', 'value' => 'Senior']]]),
            ],
            'settings.notify_emails' => ['hr@example.com'],
            'settings.confirmation_message' => 'Thanks for applying!',
        ])
        ->assertFormSet(['slug' => 'job-application'])
        ->call('create')
        ->assertHasNoFormErrors();

    $form = Form::query()->sole();

    expect($form->slug)->toBe('job-application')
        ->and($form->is_published)->toBeFalse()
        ->and(array_keys($form->definition()->getFields()))->toBe(['full_name', 'email_address', 'seniority', 'tell_us_about_a_project'])
        ->and($form->definition()->isMultiStep())->toBeTrue()
        ->and($form->definition()->getDependencies())->toBe(['seniority'])
        ->and($form->getNotificationEmails())->toBe(['hr@example.com'])
        ->and($form->getSetting('confirmation_message'))->toBe('Thanks for applying!');
});

it('keeps answer keys stable when a question is reworded', function (): void {
    $form = contactForm();

    expect($form->definition()->getField('your_name'))->not->toBeNull();

    $fields = $form->fields;
    $fields[0]['data']['label'] = 'What should we call you?';
    $form->update(['fields' => $fields]);

    expect(array_keys($form->fresh()?->definition()->getFields() ?? []))->toContain('your_name')->not->toContain('what_should_we_call_you');
});

it('validates the builder: unique and safe answer keys, known formats and operators', function (): void {
    alfakit()->testPage(CreateForm::class, panel: 'forms-admin')
        ->fillForm([
            'title' => 'Broken',
            'slug' => 'broken',
            'fields' => [
                block('text', ['label' => 'A', 'name' => 'same']),
                block('text', ['label' => 'B', 'name' => 'same']),
                block('text', ['label' => 'C', 'name' => 'Drop Table;', 'format' => 'regex:/(a+)+$/']),
                block('text', ['label' => 'D', 'name' => 'captcha']),
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['fields', 'fields.2.data.name', 'fields.2.data.format', 'fields.3.data.name']);

    expect(Form::query()->count())->toBe(0);
});

it('rejects redirect URLs that are not http(s) and invalid notification addresses', function (): void {
    alfakit()->testPage(CreateForm::class, panel: 'forms-admin')
        ->fillForm([
            'title' => 'Redirects',
            'slug' => 'redirects',
            'settings.confirmation_type' => 'redirect',
            'settings.redirect_url' => 'javascript:alert(1)',
            'settings.notify_emails' => ['nope'],
        ])
        ->call('create')
        ->assertHasFormErrors(['settings.redirect_url', 'settings.notify_emails.0']);
});

it('requires a unique address', function (): void {
    contactForm(['slug' => 'taken']);

    alfakit()->testPage(CreateForm::class, panel: 'forms-admin')
        ->fillForm(['title' => 'Taken', 'slug' => 'taken'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('edits a form and keeps its settings', function (): void {
    $form = contactForm(['settings' => ['honeypot' => true, 'confirmation_message' => 'Old']]);

    alfakit()->testPage(EditForm::class, ['record' => $form], panel: 'forms-admin')
        ->assertFormSet(['title' => 'Contact us', 'settings.confirmation_message' => 'Old'])
        ->fillForm(['settings.confirmation_message' => 'New', 'is_published' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $form->refresh();

    expect($form->getSetting('confirmation_message'))->toBe('New')
        ->and($form->is_published)->toBeFalse()
        ->and(array_keys($form->definition()->getFields()))->toBe(['your_name', 'email', 'topic', 'order', 'message']);
});

it('lists forms with their status and submission counts, duplicates and deletes them', function (): void {
    $open = contactForm(['title' => 'Open form']);
    $draft = contactForm(['title' => 'Draft form', 'is_published' => false]);
    $open->submissions()->create(['data' => []]);
    $open->submissions()->create(['data' => [], 'is_spam' => true]);

    alfakit()->testPage(ListForms::class, panel: 'forms-admin')
        ->assertCanSeeTableRecords([$open, $draft])
        ->assertTableColumnFormattedStateSet('status', 'Open', $open)
        ->assertTableColumnFormattedStateSet('status', 'Draft', $draft)
        ->assertTableColumnStateSet('submissions_count', 1, $open)
        ->filterTable('is_published', true)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters()
        ->callTableAction(ReplicateAction::class, $open)
        ->callTableAction(DeleteAction::class, $draft);

    $copy = Form::query()->where('title', 'Copy of Open form')->sole();

    expect($copy->is_published)->toBeFalse()
        ->and($copy->slug)->not->toBe($open->slug)
        ->and($copy->views_count)->toBe(0)
        ->and(Form::query()->whereKey($draft->getKey())->exists())->toBeFalse();
});

it('shows the stats of a form on its view page', function (): void {
    $form = contactForm();
    $form->forceFill(['views_count' => 8])->save();
    $form->submissions()->create(['data' => []]);
    $form->submissions()->create(['data' => []]);
    $form->submissions()->create(['data' => [], 'is_spam' => true]);

    $page = inertiaGet('/forms-admin/forms/'.$form->getKey())->assertOk()->json('props.page');
    $widget = collect(AlfaSix\AlfakitForms\Tests\flatten($page['content']))->firstWhere('type', 'StatsOverviewWidget');
    $stats = FormStatsWidget::make()->record($form)->toSchema();

    expect($widget['lazy'] ?? false)->toBeTrue();

    expect(array_column($stats['stats'], 'value'))->toBe(['8', '2', '25%', '1'])
        ->and($form->getConversionRate())->toBe(25.0);
});

it('offers a signed preview and the public link', function (): void {
    $form = contactForm(['is_published' => false]);

    alfakit()->testPage(EditForm::class, ['record' => $form], panel: 'forms-admin')
        ->assertActionVisible('preview')
        ->assertActionHidden('open');

    $form->update(['is_published' => true]);

    alfakit()->testPage(EditForm::class, ['record' => $form], panel: 'forms-admin')
        ->assertActionVisible('open')
        ->assertActionHasUrl('open', 'http://localhost/forms/'.$form->slug);
});

it('authorises everything through the plugin\'s authorize() closure', function (): void {
    $form = contactForm();
    alfakit()->getPanel('forms-admin')->getPlugin(FormsPlugin::ID)->authorize(fn (): bool => false);

    inertiaGet('/forms-admin/forms')->assertForbidden();
    inertiaGet('/forms-admin/forms/'.$form->getKey().'/edit')->assertForbidden();
});

it('limits the builder to the field types the plugin allows', function (): void {
    $builder = BuilderBlocks::make('fields', FormsPlugin::make()->fieldTypes(['text', 'email', 'nope'])->getFieldTypes());

    expect(array_keys($builder->getBlocks()))->toBe(['text', 'email']);
});
