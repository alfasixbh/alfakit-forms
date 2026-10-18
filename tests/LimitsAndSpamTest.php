<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FormStatus;
use AlfaSix\AlfakitForms\Events\FormSubmitted;
use AlfaSix\AlfakitForms\Mail\SubmissionReceived;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

use function AlfaSix\AlfakitForms\Tests\contactForm;
use function AlfaSix\AlfakitForms\Tests\fieldNames;
use function AlfaSix\AlfakitForms\Tests\publicForm;
use function AlfaSix\AlfakitForms\Tests\submitForm;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/** @return array<string, string> */
function validAnswers(): array
{
    return ['your_name' => 'Ann', 'email' => 'ann@example.com', 'topic' => 'Sales'];
}

beforeEach(function (): void {
    RateLimiter::clear('alfakit-forms');
});

// ---------------------------------------------------------------- Schedule and limits

it('reports why a form does not accept submissions', function (): void {
    expect(contactForm(['is_published' => false])->getStatus())->toBe(FormStatus::Draft)
        ->and(contactForm(['opens_at' => now()->addDay()])->getStatus())->toBe(FormStatus::Scheduled)
        ->and(contactForm(['closes_at' => now()->subMinute()])->getStatus())->toBe(FormStatus::Closed)
        ->and(contactForm(['opens_at' => now()->subDay(), 'closes_at' => now()->addDay()])->getStatus())->toBe(FormStatus::Open);
});

it('shows the closed message instead of the fields outside the open dates', function (): void {
    $scheduled = contactForm(['opens_at' => now()->addDay()]);
    $closed = contactForm(['closes_at' => now()->subMinute(), 'settings' => ['closed_message' => 'See you next year.']]);

    $content = publicForm($scheduled->slug)['content'];

    expect(array_column($content, 'type'))->not->toContain('Form')
        ->and(json_encode($content))->toContain('This form is not open yet.');

    expect(json_encode(publicForm($closed->slug)['content']))->toContain('This form is closed.')->toContain('See you next year.');
});

it('refuses submissions once the form closed, even with a token from before', function (): void {
    $form = contactForm();
    $token = publicForm($form->slug)['token'];

    $form->forceFill(['closes_at' => now()->subSecond()])->save();

    submitForm($token, validAnswers())->assertStatus(404);

    expect(FormSubmission::query()->count())->toBe(0);
});

it('closes the form when the submission limit is reached, without counting spam', function (): void {
    $form = contactForm(['max_submissions' => 2]);
    FormSubmission::query()->create(['form_id' => $form->getKey(), 'data' => [], 'is_spam' => true]);

    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();
    $token = publicForm($form->slug)['token'];
    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();

    // The render after the submit thanks the person; the next one explains the limit.
    expect($form->fresh()?->getStatus())->toBe(FormStatus::Full)
        ->and(json_encode(publicForm($form->slug)['content']))->toContain('Thank you!')
        ->and(json_encode(publicForm($form->slug)['content']))->toContain('reached its limit');

    // A token issued while there was room left: the limit is checked again on submit.
    submitForm($token, validAnswers())->assertStatus(404);

    expect($form->countSubmissions())->toBe(2);
});

// ---------------------------------------------------------------- Spam

it('accepts a filled honeypot like a real submission but stores it as spam', function (): void {
    Mail::fake();
    Event::fake([FormSubmitted::class]);

    $form = contactForm(['settings' => ['notify_emails' => ['team@example.com']]]);

    submitForm(publicForm($form->slug)['token'], [...validAnswers(), '_website' => 'https://spam.example'])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->is_spam)->toBeTrue()
        ->and($submission->data)->not->toHaveKey('_website')
        ->and($form->countSubmissions())->toBe(0)
        ->and(json_encode(publicForm($form->slug)['content']))->toContain('Thank you!');

    Mail::assertNothingQueued();
    Event::assertNotDispatched(FormSubmitted::class);
});

it('treats a form sent faster than a person can as spam', function (): void {
    config(['alfakit-forms.spam.min_seconds' => 60]);

    $form = contactForm();

    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();

    expect(FormSubmission::query()->sole()->is_spam)->toBeTrue();
});

it('drops spam entirely when configured to', function (): void {
    config(['alfakit-forms.spam.store' => false]);

    $form = contactForm();

    submitForm(publicForm($form->slug)['token'], [...validAnswers(), '_website' => 'x'])->assertRedirect();

    expect(FormSubmission::query()->count())->toBe(0);
});

it('rate limits submissions per form and IP address', function (): void {
    config(['alfakit-forms.rate_limit.max_attempts' => 2]);

    $form = contactForm();
    $other = contactForm();

    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();
    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();
    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();

    expect($form->countSubmissions())->toBe(2)
        ->and(Inertia::getFlashed()[Notification::FLASH_KEY][0]['title'] ?? null)->toBe('Too many submissions')
        ->and(Inertia::getFlashed()['alfakitAction']['status'] ?? null)->toBe('halted');

    submitForm(publicForm($other->slug)['token'], validAnswers())->assertRedirect();

    expect($other->countSubmissions())->toBe(1);
});

it('verifies the captcha on the server when the form turns it on', function (): void {
    AlfakitForms::captcha(
        component: fn () => TextInput::make('captcha')->label('Type "human"'),
        verify: fn (mixed $value, Request $request): bool => $value === 'human' && $request->ip() === '127.0.0.1',
    );

    $form = contactForm(['settings' => ['captcha' => true]]);
    $without = contactForm();
    $payload = publicForm($form->slug);

    expect(fieldNames($payload['content']))->toContain('captcha')
        ->and(fieldNames(publicForm($without->slug)['content']))->not->toContain('captcha');

    submitForm($payload['token'], [...validAnswers(), 'captcha' => 'robot'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['captcha']);

    submitForm($payload['token'], [...validAnswers(), 'captcha' => 'human'])->assertRedirect();

    expect(FormSubmission::query()->sole()->data)->not->toHaveKey('captcha');
});

it('ignores the captcha setting when the application has no captcha', function (): void {
    $form = contactForm(['settings' => ['captcha' => true]]);

    expect(fieldNames(publicForm($form->slug)['content']))->not->toContain('captcha');

    submitForm(publicForm($form->slug)['token'], validAnswers())->assertRedirect();

    expect($form->countSubmissions())->toBe(1);
});

// ---------------------------------------------------------------- Notifications

it('queues an email with the answers to the notified addresses', function (): void {
    Mail::fake();
    Event::fake([FormSubmitted::class]);

    $form = contactForm(['settings' => ['notify_emails' => ['team@example.com', 'not-an-email', 'boss@example.com', 'team@example.com']]]);

    submitForm(publicForm($form->slug)['token'], [...validAnswers(), 'message' => 'Hello there'])->assertRedirect();

    Mail::assertQueued(SubmissionReceived::class, function (SubmissionReceived $mail): bool {
        $answers = array_column($mail->getAnswers(), 'value', 'label');

        return $mail->hasTo('team@example.com')
            && $mail->hasTo('boss@example.com')
            && ! $mail->hasTo('not-an-email')
            && $answers === ['Your name' => 'Ann', 'Email' => 'ann@example.com', 'Topic' => 'Sales', 'Message' => 'Hello there'];
    });

    Event::assertDispatched(FormSubmitted::class, fn (FormSubmitted $event): bool => $event->form->is($form));
});

it('renders the notification email', function (): void {
    $form = contactForm();
    $submission = FormSubmission::query()->create(['form_id' => $form->getKey(), 'data' => ['your_name' => '<b>Ann</b>', 'topic' => 'Sales']]);

    $mail = new SubmissionReceived($submission);
    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe('New submission: Contact us')
        ->and($html)->toContain('Your name')->toContain('&lt;b&gt;Ann&lt;/b&gt;')->not->toContain('<b>Ann</b>');
});
