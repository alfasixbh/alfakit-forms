<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\AlfakitForms\AlfakitForms;

/**
 * The input formats an admin may pick for a text field. Admins never type validation
 * rules or regular expressions: they choose a format by name, and the expression behind
 * it comes from this whitelist (or from `AlfakitForms::formats()` in the application's
 * code), so a form definition can't smuggle a rule string or a catastrophic pattern.
 */
final class Formats
{
    /**
     * @return array<string, string> format name => pattern
     */
    public static function defaults(): array
    {
        return [
            'letters' => '/^[\pL\pM\s\'-]+$/u',
            'letters_numbers' => '/^[\pL\pM\pN\s]+$/u',
            'digits' => '/^[0-9]+$/',
            'alpha_dash' => '/^[\pL\pM\pN_-]+$/u',
            'uppercase' => '/^[\p{Lu}\pN\s-]+$/u',
            'postal_code' => '/^[A-Za-z0-9][A-Za-z0-9\s-]{1,9}$/',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [...self::defaults(), ...AlfakitForms::getFormats()];
    }

    public static function pattern(?string $format): ?string
    {
        if ($format === null || $format === '') {
            return null;
        }

        return self::all()[$format] ?? null;
    }

    /**
     * Format name => label, for the builder's select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (array_keys(self::all()) as $name) {
            $key = 'alfakit-forms::forms.formats.'.$name;
            $label = __($key);
            $options[$name] = $label === $key ? AlfakitForms::getFormatLabel($name) : (string) $label;
        }

        return $options;
    }
}
