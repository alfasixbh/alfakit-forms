<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Standalone\PublicForm;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

use function AlfaSix\AlfakitForms\Tests\block;
use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\fieldNames;
use function AlfaSix\AlfakitForms\Tests\flatten;
use function AlfaSix\AlfakitForms\Tests\inertiaGet;
use function AlfaSix\AlfakitForms\Tests\makeForm;
use function AlfaSix\AlfakitForms\Tests\publicForm;
use function AlfaSix\AlfakitForms\Tests\submitForm;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

// ---------------------------------------------------------------- Rendering

it('renders a published form on the public route as a standalone component', function (): void {
    $form = contactForm(['description' => 'We answer within a day.']);

    $response = inertiaGet('/forms/'.$form->slug)->assertOk();

    expect($response->json('component'))->toBe('alfakit-forms/form')
        ->and($response->json('props.meta'))->toBe(['title' => 'Contact us', 'description' => 'We answer within a day.', 'slug' => $form->slug, 'preview' => false, 'poweredBy' => null]);

    $payload = $response->json('props.form');
    $context = SchemaContext::fromToken($payload['token']);

    expect($payload['alfakit'])->toBe('standalone')
        ->and($payload['class'])->toBe(PublicForm::class)
        ->and($payload['heading'])->toBe('Contact us')
        ->and($payload['subheading'])->toBe('We answer within a day.')
        ->and($payload['content'][0]['type'])->toBe('Form')
        ->and(array_column($payload['content'][0]['actions'], 'label'))->toBe(['Submit'])
        ->and($context->panel)->toBe('standalone')
        ->and($context->parameters['definition'])->toBe(['@model' => Form::class, 'key' => $form->getKey()]);

    // The order number only shows for support questions; the topic re-renders the form.
    $names = fieldNames($payload['content']);
    $topic = collect(flatten($payload['content']))->firstWhere('statePath', 'topic');

    expect($names)->toBe(['your_name', 'email', 'topic', 'message', '_website'])
        ->and($topic['live'] ?? null)->not->toBeNull();
});

it('passes the opt-in "Powered by" credit to the page when the config turns it on', function (): void {
    $form = contactForm();
    config(['alfakit.credit.public_pages' => true]);

    expect(inertiaGet('/forms/'.$form->slug)->assertOk()->json('props.meta.poweredBy'))
        ->toContain('data-slot="powered-by"');
});

it('hides the honeypot from people and assistive technology', function (): void {
    $honeypot = collect(flatten(publicForm(contactForm()->slug)['content']))->firstWhere('statePath', '_website');

    expect($honeypot['extraFieldWrapperAttributes']['aria-hidden'])->toBe('true')
        // Clipped where it stands: an offset towards the start widened right-to-left pages.
        ->and($honeypot['extraFieldWrapperAttributes']['style'])->toContain('clip-path:inset(50%)')->not->toContain('-10000px')
        ->and($honeypot['extraInputAttributes']['tabindex'])->toBe('-1')
        ->and($honeypot['autocomplete'])->toBe('off');

    $form = contactForm(['settings' => ['honeypot' => false]]);

    expect(fieldNames(publicForm($form->slug)['content']))->not->toContain('_website');
});

it('answers 404 for unknown and unpublished forms', function (): void {
    $draft = contactForm(['is_published' => false]);

    inertiaGet('/forms/nope')->assertNotFound();
    inertiaGet('/forms/'.$draft->slug)->assertNotFound();
    inertiaGet('/forms/'.$draft->slug.'?preview=1')->assertNotFound();
});

it('shows drafts read-only through a signed preview link', function (): void {
    $draft = contactForm(['is_published' => false]);
    $url = URL::temporarySignedRoute('alfakit-forms.show', now()->addHour(), ['slug' => $draft->slug, 'preview' => 1]);

    $payload = inertiaGet($url)->assertOk()->json('props.form');

    expect($payload['content'][0]['actions'])->toBe([])
        ->and(json_encode($payload['content']))->toContain('answers are not saved')
        ->and($draft->fresh()?->views_count)->toBe(0);

    submitForm($payload['token'], ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('counts a view once per session', function (): void {
    $form = contactForm();

    inertiaGet('/forms/'.$form->slug)->assertOk();
    inertiaGet('/forms/'.$form->slug)->assertOk();

    expect($form->fresh()?->views_count)->toBe(1)
        ->and($form->fresh()?->updated_at?->equalTo($form->updated_at))->toBeTrue();
});

it('embeds a published form in any Inertia page', function (): void {
    $form = contactForm(['slug' => 'newsletter']);
    $draft = contactForm(['slug' => 'secret', 'is_published' => false]);

    Route::middleware('web')->get('/landing', fn () => Inertia::render('Landing', ['signup' => AlfakitForms::embed('newsletter', ['email' => 'hi@example.com'])]));
    Route::middleware('web')->get('/hidden', fn () => Inertia::render('Landing', ['signup' => AlfakitForms::embed($draft)]));

    $payload = inertiaGet('/landing')->assertOk()->json('props.signup');

    expect($payload['content'][0]['state']['email'])->toBe('hi@example.com')
        ->and($form->fresh()?->views_count)->toBe(1);

    inertiaGet('/hidden')->assertNotFound();
});

it('renders page breaks as a wizard with one step per page', function (): void {
    $form = makeForm([
        block('text', ['label' => 'Name', 'required' => true]),
        block('page_break', ['label' => 'About you', 'description' => 'Two more questions']),
        block('radio', ['label' => 'Role', 'options' => ['Developer', 'Designer']]),
        block('rating', ['label' => 'How likely are you to recommend us?', 'max' => 5]),
        block('page_break'),
    ], ['settings' => ['first_page_label' => 'Basics']]);

    $content = publicForm($form->slug)['content'][0];
    $wizard = collect(flatten($content['components']))->firstWhere('type', 'Wizard');
    $steps = $wizard['schema'];
    $rating = collect(flatten($content['components']))->firstWhere('statePath', 'how_likely_are_you_to_recommend_us');

    expect($content['wizard'] ?? false)->toBeTrue()
        ->and(array_column($steps, 'label'))->toBe(['Basics', 'About you'])
        ->and($steps[1]['description'] ?? null)->toBe('Two more questions')
        ->and($rating['type'])->toBe('ToggleButtons')
        ->and(array_map(strval(...), array_column($rating['options'], 'value')))->toBe(['1', '2', '3', '4', '5']);
});

// ---------------------------------------------------------------- Submitting

it('validates a submission against the stored definition', function (): void {
    $form = contactForm();
    $token = publicForm($form->slug)['token'];

    submitForm($token, ['your_name' => str_repeat('x', 51), 'email' => 'not-an-email', 'topic' => 'Hacking'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['your_name', 'email', 'topic'])
        ->assertJsonMissingValidationErrors(['order', 'message']);

    submitForm($token, [])->assertStatus(422)->assertJsonValidationErrors(['your_name', 'email', 'topic']);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('stores the visible answers, shows the confirmation and empties the form', function (): void {
    $form = contactForm(['settings' => ['confirmation_message' => 'We will be in touch.']]);
    $token = publicForm($form->slug)['token'];

    submitForm($token, [
        'your_name' => 'Ann',
        'email' => 'ann@example.com',
        'topic' => 'Sales',
        'order' => '12345',
        'message' => 'Hello',
        'unknown' => 'dropped',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->data)->toBe(['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales', 'message' => 'Hello'])
        ->and($submission->is_spam)->toBeFalse()
        ->and($submission->form_id)->toBe($form->getKey())
        ->and($submission->ip_address)->toBe('127.0.0.1');

    // The next render shows the confirmation instead of the fields.
    $after = publicForm($form->slug);

    expect(array_column($after['content'], 'type'))->not->toContain('Form')
        ->and(json_encode($after['content']))->toContain('We will be in touch.');

    // And the one after that, the form again.
    expect(publicForm($form->slug)['content'][0]['type'])->toBe('Form');
});

it('rejects answers that are not one of the options and formats that do not match', function (): void {
    $form = makeForm([
        block('checkbox_list', ['label' => 'Interests', 'options' => ['A', 'B', 'C'], 'max_items' => 2]),
        block('text', ['label' => 'Code', 'format' => 'digits']),
        block('number', ['label' => 'Age', 'min' => 18, 'max' => 120, 'integer' => true]),
        block('toggle', ['label' => 'I accept the terms', 'name' => 'terms', 'required' => true]),
    ]);
    $token = publicForm($form->slug)['token'];

    submitForm($token, ['interests' => ['A', 'Z'], 'code' => '12a', 'age' => 12, 'terms' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['interests.1', 'code', 'age', 'terms']);

    submitForm($token, ['interests' => ['A', 'B', 'C'], 'code' => '123', 'age' => 30, 'terms' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['interests']);

    submitForm($token, ['interests' => ['A', 'C'], 'code' => '123', 'age' => 30, 'terms' => true])->assertRedirect();

    expect(FormSubmission::query()->sole()->data)->toMatchArray(['interests' => ['A', 'C'], 'code' => '123', 'terms' => true]);
});

it('redirects to the confirmation URL, never to a script URL', function (): void {
    $form = contactForm(['settings' => ['confirmation_type' => 'redirect', 'redirect_url' => 'https://example.com/thanks']]);

    submitForm(publicForm($form->slug)['token'], ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://example.com/thanks');

    expect(PublicForm::safeRedirectUrl('javascript:alert(1)'))->toBeNull()
        ->and(PublicForm::safeRedirectUrl('//evil.test'))->toBeNull()
        ->and(PublicForm::safeRedirectUrl('/thanks'))->toBe('/thanks');

    $evil = contactForm(['settings' => ['confirmation_type' => 'redirect', 'redirect_url' => 'javascript:alert(1)']]);

    submitForm(publicForm($evil->slug)['token'], ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales'])
        ->assertRedirect();
});

it('records the signed-in user as the submitter', function (): void {
    $user = AlfaSix\AlfakitForms\Tests\user();
    $form = contactForm();

    Pest\Laravel\actingAs($user);

    submitForm(publicForm($form->slug)['token'], ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales'])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->submitter_id)->toBe($user->getKey())
        ->and($submission->submitter_type)->toBe($user->getMorphClass());
});
