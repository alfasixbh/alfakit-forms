<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms;

use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\AlfakitForms\Commands\MakeFormPageCommand;
use AlfaSix\AlfakitForms\Mail\SubmissionReceived;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Support\FormDefinition;
use AlfaSix\AlfakitForms\Support\FormsManager;
use Illuminate\Support\ServiceProvider;

class AlfakitFormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alfakit-forms.php', 'alfakit-forms');

        $this->app->singleton(FormsManager::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-forms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'alfakit-forms');

        static::registerMails();

        if ((bool) config('alfakit-forms.route.enabled', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MakeFormPageCommand::class]);

            $this->publishes([
                __DIR__.'/../config/alfakit-forms.php' => config_path('alfakit-forms.php'),
            ], 'alfakit-forms-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-forms-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-forms'),
            ], 'alfakit-forms-translations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/alfakit-forms'),
            ], 'alfakit-forms-views');
        }
    }

    /**
     * The submission mail, for the mail preview page and editable templates.
     */
    protected static function registerMails(): void
    {
        Mails::register(
            MailDefinition::make(SubmissionReceived::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit-forms::forms.mail_templates.submission'))
                ->group(static fn (): string => (string) __('alfakit-forms::forms.mail_templates.group'))
                ->preview(static function (): SubmissionReceived {
                    $form = new Form(['title' => 'Contact us', 'fields' => FormDefinition::normalize([
                        ['type' => 'text', 'data' => ['label' => 'Your name', 'name' => 'name']],
                        ['type' => 'email', 'data' => ['label' => 'Email', 'name' => 'email']],
                        ['type' => 'textarea', 'data' => ['label' => 'Message', 'name' => 'message']],
                    ])]);
                    $submission = new FormSubmission(['data' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'I would like a demo of the analytical engine.']]);
                    $submission->setAttribute('created_at', now());
                    $submission->setRelation('form', $form);

                    return new SubmissionReceived($submission);
                })
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['form', 'answers', 'date']),
                    subject: static fn (): string => (string) __('alfakit-forms::forms.mail.subject', ['form' => '{{ form }}']),
                    body: static fn (): string => '<h1>'.MailDefinition::tag('form').'</h1>'.MailDefinition::paragraphs(
                        (string) __('alfakit-forms::forms.mail.submitted_at', ['date' => '{{ date }}']),
                        '{{ answers }}',
                    ),
                ),
        );
    }
}
