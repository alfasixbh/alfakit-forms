<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\Exports\Testing\ExportsFake;
use AlfaSix\AlfakitForms\Exports\SubmissionExporter;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages\ViewForm;
use AlfaSix\AlfakitForms\Resources\FormResource\RelationManagers\SubmissionsRelationManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function AlfaSix\AlfakitForms\Tests\block;
use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\makeForm;
use function AlfaSix\AlfakitForms\Tests\user;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    actingAs(user());
});

it('lists the submissions of a form, hiding spam by default', function (): void {
    $form = contactForm();
    $other = contactForm();
    $ann = $form->submissions()->create(['data' => ['your_name' => 'Ann', 'topic' => 'Sales']]);
    $spam = $form->submissions()->create(['data' => ['your_name' => 'Bot'], 'is_spam' => true]);
    $elsewhere = $other->submissions()->create(['data' => ['your_name' => 'Eve']]);
    $arabic = $form->submissions()->create(['data' => ['your_name' => 'محمد', 'topic' => 'Support']]);

    alfakit()->testPage(ViewForm::class, ['record' => $form], panel: 'forms-admin')
        ->relationManager(SubmissionsRelationManager::class)
        ->assertCanSeeTableRecords([$ann])
        ->assertCanNotSeeTableRecords([$spam, $elsewhere])
        ->assertTableColumnFormattedStateSet('answer_your_name', 'Ann', $ann)
        ->filterTable('is_spam', true)
        ->assertCanSeeTableRecords([$spam])
        ->assertCanNotSeeTableRecords([$ann])
        ->removeTableFilters()
        ->searchTable('Bot')
        ->assertCanSeeTableRecords([$spam])
        ->assertCanNotSeeTableRecords([$ann])
        ->searchTable('محمد')
        ->assertCanSeeTableRecords([$arabic])
        ->assertCanNotSeeTableRecords([$ann, $spam]);
});

it('marks submissions as spam and back, and deletes them', function (): void {
    $form = contactForm();
    $submission = $form->submissions()->create(['data' => ['your_name' => 'Ann']]);
    $second = $form->submissions()->create(['data' => ['your_name' => 'Bob']]);

    alfakit()->testPage(ViewForm::class, ['record' => $form], panel: 'forms-admin')
        ->relationManager(SubmissionsRelationManager::class)
        ->callTableAction('markAsSpam', $submission)
        ->assertNotified('Marked as spam');

    expect($submission->fresh()?->is_spam)->toBeTrue();

    alfakit()->testPage(ViewForm::class, ['record' => $form], panel: 'forms-admin')
        ->relationManager(SubmissionsRelationManager::class)
        ->filterTable('is_spam', true)
        ->callTableAction('markAsNotSpam', $submission)
        ->removeTableFilters()
        ->callTableAction(DeleteAction::class, $second)
        ->callTableBulkAction('markAsSpam', [$submission]);

    expect($submission->fresh()?->is_spam)->toBeTrue()
        ->and(FormSubmission::query()->whereKey($second->getKey())->exists())->toBeFalse();
});

it('shows every answer in the view modal with signed links to uploaded files', function (): void {
    Storage::fake('local');

    $form = makeForm([
        block('text', ['label' => 'Name']),
        block('file', ['label' => 'CV', 'name' => 'cv', 'file_types' => 'pdf']),
        block('toggle', ['label' => 'Relocate', 'name' => 'relocate']),
    ]);
    Storage::disk('local')->put('alfakit-forms/'.$form->getKey().'/cv.pdf', '%PDF-1.4');
    $submission = $form->submissions()->create(['data' => ['name' => 'Ann', 'cv' => 'alfakit-forms/'.$form->getKey().'/cv.pdf', 'relocate' => true]]);

    $schema = AlfaSix\Alfakit\Schemas\Schema::make()->record($submission)->components(SubmissionsRelationManager::getAnswerEntries($form, $submission));
    $entries = AlfaSix\AlfakitForms\Tests\flatten($schema->toSchema());
    $file = collect($entries)->firstWhere('key', 'answer_cv_0');

    expect(collect($entries)->firstWhere('key', 'answer_relocate')['state'] ?? null)->toBe('Yes')
        ->and($file['state'])->toBe('cv.pdf')
        ->and($file['url'])->toContain('/forms/_files/'.$submission->getKey().'/cv/0')->toContain('signature=');

    get($file['url'])->assertOk()->assertDownload('cv.pdf');

    // Unsigned, tampered or pointing at another answer: refused.
    get('/forms/_files/'.$submission->getKey().'/cv/0')->assertForbidden();
    get(str_replace('/cv/0', '/name/0', $file['url']))->assertForbidden();

    // Deleting the submission deletes its files.
    $submission->delete();

    Storage::disk('local')->assertMissing('alfakit-forms/'.$form->getKey().'/cv.pdf');

    // Deleting the form deletes its submissions and the rest of its files.
    Storage::disk('local')->put('alfakit-forms/'.$form->getKey().'/other.pdf', '%PDF-1.4');
    $form->submissions()->create(['data' => ['name' => 'Bob']]);
    $form->delete();

    Storage::disk('local')->assertMissing('alfakit-forms/'.$form->getKey().'/other.pdf');

    expect(FormSubmission::query()->count())->toBe(0);
});

it('exports the answers with one column per field', function (): void {
    $form = makeForm([
        block('text', ['label' => 'Name']),
        block('checkbox_list', ['label' => 'Interests', 'options' => ['A', 'B']]),
        block('toggle', ['label' => 'Newsletter']),
        block('text', ['label' => 'Formula']),
    ]);
    $submission = $form->submissions()->create(['data' => ['name' => 'Ann', 'interests' => ['A', 'B'], 'newsletter' => false, 'formula' => '=HYPERLINK("http://evil")']]);

    $export = SubmissionExporter::test(options: ['form' => $form->getKey()]);

    expect($export->headers())->toBe(['ID', 'Submitted', 'Spam', 'Name', 'Interests', 'Newsletter', 'Formula'])
        ->and(array_slice($export->export($submission), 2))->toBe(['No', 'Ann', 'A, B', 'No', '=HYPERLINK("http://evil")']);
});

it('starts an export of the form\'s submissions from the relation manager', function (): void {
    $fake = ExportsFake::fake();
    $form = contactForm();
    $form->submissions()->create(['data' => ['your_name' => 'Ann']]);
    contactForm()->submissions()->create(['data' => ['your_name' => 'Eve']]);

    alfakit()->testPage(ViewForm::class, ['record' => $form], panel: 'forms-admin')
        ->relationManager(SubmissionsRelationManager::class)
        ->callTableAction(ExportAction::class);

    $fake->assertDispatched(SubmissionExporter::class, fn ($export, $query, array $columnMap, array $options, array $formats, ?array $records): bool => ($options['form'] ?? null) === $form->getKey() && count($records ?? []) === 1);
});

it('stores public uploads on the configured private disk', function (): void {
    Storage::fake('local');

    $form = makeForm([block('file', ['label' => 'Photo', 'name' => 'photo', 'file_types' => 'images', 'required' => true])]);
    $payload = AlfaSix\AlfakitForms\Tests\publicForm($form->slug);

    $upload = Pest\Laravel\post('/_alfakit/uploads', [
        'token' => $payload['token'],
        'field' => 'photo',
        'file' => UploadedFile::fake()->image('me.png'),
    ], ['Accept' => 'application/json'])->assertOk()->json();

    AlfaSix\AlfakitForms\Tests\submitForm($payload['token'], ['photo' => [$upload['key'] ?? $upload['id'] ?? $upload]])->assertRedirect();

    $path = FormSubmission::query()->sole()->getAnswer('photo');

    expect($path)->toBeString()->toStartWith('alfakit-forms/'.$form->getKey().'/');

    Storage::disk('local')->assertExists($path);
});
