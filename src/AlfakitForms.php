<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms;

use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Standalone\PublicForm;
use AlfaSix\AlfakitForms\Support\FormsManager;
use Closure;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Entry point of the public side of the forms plugin.
 *
 * ```php
 * // A published form as a prop of any Inertia page:
 * Route::get('/', fn () => Inertia::render('home', [
 *     'newsletter' => AlfakitForms::embed('newsletter'),
 * ]));
 *
 * // In a service provider's boot():
 * AlfakitForms::format('vat_number', '/^[A-Z]{2}[0-9A-Z]{8,12}$/', 'VAT number');
 * AlfakitForms::captcha(
 *     component: fn (Form $form) => TurnstileField::make('captcha'),
 *     verify: fn (mixed $value, Request $request) => Turnstile::verify($value, $request->ip()),
 * );
 * ```
 */
final class AlfakitForms
{
    public static function manager(): FormsManager
    {
        return app(FormsManager::class);
    }

    /**
     * A published form as a standalone component, ready to be passed as an Inertia prop
     * and rendered with `<AlfakitComponent component={…} />`. Counts a view (once per
     * session). 404 when the form doesn't exist or isn't published.
     *
     * @param  array<string, mixed>  $fill
     */
    public static function embed(Form|string $form, array $fill = []): PublicForm
    {
        $model = $form instanceof Form ? $form : self::findPublished($form);

        abort_if($model === null || ! $model->isPublished(), 404);

        $component = PublicForm::for($model);
        $component->recordView();

        return $fill === [] ? $component : $component->fill($fill);
    }

    /**
     * The published form with this slug, or null.
     */
    public static function findPublished(string $slug): ?Form
    {
        $model = self::formModel();

        /** @var Form|null */
        return $model::query()->where('slug', $slug)->where('is_published', true)->first();
    }

    /**
     * The public URL of a form (null when the public route is turned off).
     */
    public static function url(Form $form): ?string
    {
        $name = self::routeName('show');

        return Route::has($name) ? route($name, ['slug' => $form->slug]) : null;
    }

    public static function routeName(string $name): string
    {
        return ((string) config('alfakit-forms.route.name', 'alfakit-forms.')).$name;
    }

    /**
     * Add a text format admins can pick (see `FormsManager::format()`).
     */
    public static function format(string $name, string $pattern, ?string $label = null): FormsManager
    {
        return self::manager()->format($name, $pattern, $label);
    }

    /**
     * @return array<string, string>
     */
    public static function getFormats(): array
    {
        return self::manager()->getFormats();
    }

    public static function getFormatLabel(string $name): string
    {
        return self::manager()->getFormatLabel($name);
    }

    /**
     * Add a captcha to the forms that turn it on (see `FormsManager::captcha()`).
     */
    public static function captcha(Closure $component, Closure $verify): FormsManager
    {
        return self::manager()->captcha($component, $verify);
    }

    /**
     * @return class-string<Form>
     */
    public static function formModel(): string
    {
        $model = config('alfakit-forms.models.form', Form::class);

        if (! is_string($model) || ! is_a($model, Form::class, true)) {
            throw new InvalidArgumentException('The alfakit-forms.models.form model must extend ['.Form::class.'].');
        }

        return $model;
    }

    /**
     * @return class-string<FormSubmission>
     */
    public static function submissionModel(): string
    {
        $model = config('alfakit-forms.models.submission', FormSubmission::class);

        if (! is_string($model) || ! is_a($model, FormSubmission::class, true)) {
            throw new InvalidArgumentException('The alfakit-forms.models.submission model must extend ['.FormSubmission::class.'].');
        }

        return $model;
    }
}
