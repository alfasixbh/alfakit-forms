<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\AlfakitForms\Models\Form;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Application-wide options of the public side of the forms plugin (it runs outside any
 * panel). Configure it through the `AlfakitForms` static helpers, usually in a service
 * provider's `boot()`. A container singleton, so it holds no state between requests
 * beyond what the application registers at boot.
 */
class FormsManager
{
    /** @var array<string, array{pattern: string, label: string|null}> */
    protected array $formats = [];

    protected ?Closure $captchaComponentUsing = null;

    protected ?Closure $captchaVerifyUsing = null;

    /**
     * Add a format admins can pick for text fields. The pattern is yours (trusted code),
     * never something an admin typed.
     */
    public function format(string $name, string $pattern, ?string $label = null): static
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("The form field format name [{$name}] must be lowercase letters, digits and underscores.");
        }

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("The pattern of the form field format [{$name}] is not a valid regular expression.");
        }

        $this->formats[$name] = ['pattern' => $pattern, 'label' => $label];

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getFormats(): array
    {
        return array_map(static fn (array $format): string => $format['pattern'], $this->formats);
    }

    public function getFormatLabel(string $name): string
    {
        return $this->formats[$name]['label'] ?? str_replace('_', ' ', ucfirst($name));
    }

    /**
     * Add a captcha to the forms that turn it on: `$component` returns the field that shows
     * the challenge (`fn (Form $form): Component`), `$verify` checks its answer on the
     * server (`fn (mixed $value, Request $request, Form $form): bool`).
     */
    public function captcha(Closure $component, Closure $verify): static
    {
        $this->captchaComponentUsing = $component;
        $this->captchaVerifyUsing = $verify;

        return $this;
    }

    public function hasCaptcha(): bool
    {
        return $this->captchaComponentUsing !== null && $this->captchaVerifyUsing !== null;
    }

    public function getCaptchaComponent(Form $form): ?Component
    {
        if ($this->captchaComponentUsing === null) {
            return null;
        }

        $component = app()->call($this->captchaComponentUsing, ['form' => $form]);

        return $component instanceof Component ? $component : null;
    }

    public function verifyCaptcha(mixed $value, Request $request, Form $form): bool
    {
        if ($this->captchaVerifyUsing === null) {
            return true;
        }

        return (bool) app()->call($this->captchaVerifyUsing, ['value' => $value, 'request' => $request, 'form' => $form]);
    }
}
