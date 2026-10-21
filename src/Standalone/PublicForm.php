<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Standalone;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form as StandaloneForm;
use AlfaSix\Alfakit\Support\Url;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FormStatus;
use AlfaSix\AlfakitForms\Events\FormSubmitted;
use AlfaSix\AlfakitForms\Mail\SubmissionReceived;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Support\PublicSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A form built in the panel, rendered outside it: on the plugin's public route or on any
 * Inertia page (`AlfakitForms::embed('contact')`). The schema is rebuilt from the stored
 * definition on every request, so the fields, their rules and the conditional logic
 * that validate a submission are exactly the ones the admin saved; nothing the browser
 * sends can add a rule or reveal a hidden field.
 */
class PublicForm extends StandaloneForm
{
    /** Session key (suffixed with the form key) of the confirmation shown after a submit. */
    public const SUBMITTED_FLASH_KEY = 'alfakit-forms.submitted';

    /** Session key of the forms this session has viewed (views count once per session). */
    public const VIEWED_SESSION_KEY = 'alfakit-forms.viewed';

    public ?Form $definition = null;

    /** A signed preview from the panel: the form shows, nothing is saved. */
    public bool $preview = false;

    /** When the form was shown (Unix time), carried in the token for the spam time check. */
    public ?int $issuedAt = null;

    public static function for(Form $form, bool $preview = false): static
    {
        return static::make(['definition' => $form, 'preview' => $preview, 'issuedAt' => time()]);
    }

    public function mount(mixed ...$parameters): void
    {
        $form = $this->getDefinition();

        $this->heading($form->title);
        $this->subheading(is_string($form->description) && trim($form->description) !== '' ? $form->description : null);
    }

    public function authorize(): bool
    {
        return $this->definition instanceof Form && ($this->definition->isPublished() || $this->preview);
    }

    public function getDefinition(): Form
    {
        abort_unless($this->definition instanceof Form, 404);

        return $this->definition;
    }

    /**
     * Count a view of the form, once per session. Previews don't count.
     */
    public function recordView(): void
    {
        if ($this->preview) {
            return;
        }

        $form = $this->getDefinition();
        $key = (string) $form->getKey();
        $session = request()->hasSession() ? request()->session() : null;
        $viewed = $session?->get(self::VIEWED_SESSION_KEY, []);
        $viewed = is_array($viewed) ? $viewed : [];

        if (in_array($key, $viewed, true)) {
            return;
        }

        $form->incrementViews();
        $session?->put(self::VIEWED_SESSION_KEY, array_slice([...$viewed, $key], -200));
    }

    // ---------------------------------------------------------------- State

    /**
     * Whether this render follows a successful submit (the confirmation shows instead).
     */
    public function isSubmitted(): bool
    {
        // Only page renders show it: the internal requests that follow (a new submission,
        // a schema refresh) work on the form itself.
        if (request()->routeIs($this->getPanel()->generateRouteName('internal.*'))) {
            return false;
        }

        return request()->hasSession() && request()->session()->has(static::getSubmittedFlashKey($this->getDefinition()));
    }

    public static function getSubmittedFlashKey(Form $form): string
    {
        return self::SUBMITTED_FLASH_KEY.'.'.$form->getKey();
    }

    public function showsFields(): bool
    {
        if ($this->isSubmitted()) {
            return false;
        }

        return $this->preview || $this->getDefinition()->acceptsSubmissions();
    }

    // ---------------------------------------------------------------- Schema

    public function form(Schema $schema): Schema
    {
        if (! $this->showsFields()) {
            return $schema;
        }

        $form = $this->getDefinition();
        $captcha = $this->getCaptchaComponent();

        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                ...($this->preview ? [
                    Callout::make(__('alfakit-forms::forms.public.preview.heading'))
                        ->description(__('alfakit-forms::forms.public.preview.description'))
                        ->info()
                        ->columnSpanFull(),
                ] : []),
                ...(new PublicSchema($form))->components($captcha === null ? [] : [$captcha]),
                ...($this->usesHoneypot() ? [$this->getHoneypotComponent()] : []),
            ]);
    }

    /**
     * The confirmation after a submit, or why the form doesn't accept answers.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->showsFields()) {
            return $schema;
        }

        $form = $this->getDefinition();

        if ($this->isSubmitted()) {
            $message = $form->getSetting('confirmation_message');

            return $schema->components([
                Callout::make(__('alfakit-forms::forms.public.submitted.heading'))
                    ->description(is_string($message) && trim($message) !== '' ? $message : __('alfakit-forms::forms.public.submitted.description'))
                    ->success()
                    ->icon('circle-check'),
            ]);
        }

        $status = $form->getStatus();
        $message = $form->getSetting('closed_message');

        return $schema->components([
            Callout::make(__('alfakit-forms::forms.public.closed.'.$status->value))
                ->description(is_string($message) && trim($message) !== '' ? $message : null)
                ->color($status === FormStatus::Scheduled ? 'info' : 'warning')
                ->icon($status === FormStatus::Scheduled ? 'calendar-clock' : 'lock'),
        ]);
    }

    protected function hasWizardForm(): bool
    {
        return $this->showsFields() && $this->getDefinition()->definition()->isMultiStep();
    }

    public function getSubmitLabel(): string
    {
        $label = $this->definition?->getSetting('submit_label');

        return is_string($label) && trim($label) !== '' ? trim($label) : (string) __('alfakit-forms::forms.public.submit');
    }

    /**
     * @return array<Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        return $this->showsFields() && ! $this->preview ? parent::getFormActions() : [];
    }

    protected function usesHoneypot(): bool
    {
        return (bool) $this->getDefinition()->getSetting('honeypot', true);
    }

    public static function getHoneypotFieldName(): string
    {
        $name = config('alfakit-forms.spam.honeypot_field', '_website');

        return is_string($name) && $name !== '' ? $name : '_website';
    }

    /**
     * A text input people never see (off screen, hidden from assistive technology and
     * out of the tab order); bots that fill every input fill it too.
     */
    protected function getHoneypotComponent(): TextInput
    {
        return TextInput::make(static::getHoneypotFieldName())
            ->label(__('alfakit-forms::forms.public.honeypot'))
            ->autocomplete(false)
            ->dehydrated()
            ->columnSpanFull()
            ->extraFieldWrapperAttributes([
                'aria-hidden' => 'true',
                'data-alfakit-forms-honeypot' => 'true',
                // Clipped in place: pushed 10000px towards the start it widened right-to-left
                // pages by 10000px (a horizontal scrollbar on every Arabic form).
                'style' => 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0',
            ])
            ->extraInputAttributes(['tabindex' => '-1']);
    }

    protected function usesCaptcha(): bool
    {
        return (bool) $this->getDefinition()->getSetting('captcha', false) && AlfakitForms::manager()->hasCaptcha();
    }

    protected function getCaptchaComponent(): ?Component
    {
        return $this->usesCaptcha() ? AlfakitForms::manager()->getCaptchaComponent($this->getDefinition()) : null;
    }

    // ---------------------------------------------------------------- Submit

    /**
     * Store the answers of a validated submission. Validation already ran against the
     * stored definition (only visible fields, with the admin's rules); this adds the
     * checks that aren't about single fields: preview, rate limit, captcha, spam, the
     * schedule and the submission limit.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        $form = $this->getDefinition();

        if ($this->preview) {
            $this->failWith(__('alfakit-forms::forms.public.preview.heading'), __('alfakit-forms::forms.public.preview.description'));
        }

        $this->ensureNotRateLimited($form);
        $this->ensureCaptchaPassed($form, $data);

        $isSpam = $this->isSpam($data);
        $submission = DB::transaction(function () use ($form, $data, $isSpam): ?FormSubmission {
            // Lock the form so two last-minute submissions can't both pass the limit.
            $locked = $form->newQuery()->whereKey($form->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Form || ! $locked->acceptsSubmissions()) {
                return null;
            }

            if ($isSpam && ! config('alfakit-forms.spam.store', true)) {
                return new (AlfakitForms::submissionModel());
            }

            return $this->storeSubmission($locked, $data, $isSpam);
        });

        if ($submission === null) {
            $this->failWith(__('alfakit-forms::forms.public.closed.'.$form->getStatus()->value));
        }

        if ($submission->exists && ! $isSpam) {
            event(new FormSubmitted($form, $submission));
            $this->sendNotifications($form, $submission);
        }

        $this->reset();

        if (request()->hasSession()) {
            request()->session()->flash(static::getSubmittedFlashKey($form), true);
        }

        $redirect = $form->getSetting('confirmation_type') === 'redirect' ? static::safeRedirectUrl($form->getSetting('redirect_url')) : null;

        if ($redirect !== null) {
            return request()->header('X-Inertia') ? Inertia::location($redirect) : redirect()->away($redirect);
        }

        return back();
    }

    /**
     * Only absolute http(s) URLs and paths of this application: never `javascript:`.
     */
    public static function safeRedirectUrl(mixed $url): ?string
    {
        $url = Url::sanitize($url, ['http', 'https']);

        if ($url === null) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://', '/']) && ! Str::startsWith($url, ['//', '/\\']) ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function storeSubmission(Form $form, array $data, bool $isSpam): FormSubmission
    {
        $model = AlfakitForms::submissionModel();
        $user = $this->getPanel()->auth()->user();
        $request = request();
        $storesIp = (bool) config('alfakit-forms.store_ip_address', true);

        /** @var FormSubmission $submission */
        $submission = new $model;
        $submission->forceFill([
            'form_id' => $form->getKey(),
            'tenant_type' => $form->tenant_type,
            'tenant_id' => $form->tenant_id,
            'data' => $form->definition()->filterAnswers($data),
            'is_spam' => $isSpam,
            'ip_address' => $storesIp ? $request->ip() : null,
            'user_agent' => $storesIp ? mb_substr((string) $request->userAgent(), 0, 512) : null,
        ]);

        if ($user instanceof Model) {
            $submission->submitter()->associate($user);
        }

        $submission->save();

        return $submission;
    }

    protected function sendNotifications(Form $form, FormSubmission $submission): void
    {
        $emails = $form->getNotificationEmails();

        if ($emails === []) {
            return;
        }

        $mail = new SubmissionReceived($submission);
        $mail->onConnection(config('alfakit-forms.mail.connection'));
        $mail->onQueue(config('alfakit-forms.mail.queue'));

        Mail::to($emails)->queue($mail);
    }

    protected function ensureNotRateLimited(Form $form): void
    {
        $key = 'alfakit-forms:'.$form->getKey().':'.sha1((string) request()->ip());
        $max = max(1, (int) config('alfakit-forms.rate_limit.max_attempts', 5));
        $decay = max(1, (int) config('alfakit-forms.rate_limit.decay_seconds', 60));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->failWith(
                __('alfakit-forms::forms.public.rate_limited.title'),
                __('alfakit-forms::forms.public.rate_limited.body', ['seconds' => RateLimiter::availableIn($key)]),
            );
        }

        RateLimiter::hit($key, $decay);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ensureCaptchaPassed(Form $form, array $data): void
    {
        $component = $this->getCaptchaComponent();

        if ($component === null) {
            return;
        }

        $name = method_exists($component, 'getName') ? (string) $component->getName() : 'captcha';

        if (! AlfakitForms::manager()->verifyCaptcha($data[$name] ?? request()->input('data.'.$name), request(), $form)) {
            throw ValidationException::withMessages([$name => __('alfakit-forms::forms.public.captcha_failed')]);
        }
    }

    /**
     * A filled honeypot, or a form sent faster than a person can fill it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function isSpam(array $data): bool
    {
        if ($this->usesHoneypot()) {
            $honeypot = $data[static::getHoneypotFieldName()] ?? request()->input('data.'.static::getHoneypotFieldName());

            if (is_string($honeypot) && trim($honeypot) !== '') {
                return true;
            }
        }

        $minimum = max(0, (int) config('alfakit-forms.spam.min_seconds', 2));

        return $minimum > 0 && $this->issuedAt !== null && (time() - $this->issuedAt) < $minimum;
    }

    /**
     * Stop the submit with a notification; the answers stay in the form.
     */
    protected function failWith(string $title, ?string $body = null): never
    {
        Notification::make()->danger()->title($title)->body($body)->send();

        throw new Halt;
    }
}
