<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\AlfakitForms\Enums\FieldType;
use Stringable;

/**
 * Answers as admins read them: in the submission view, the table, exports and emails.
 */
final class Answers
{
    /**
     * @param  array{type: string, data: array<string, mixed>}|null  $field
     */
    public static function format(?array $field, mixed $value): ?string
    {
        $type = $field === null ? null : FieldType::tryFrom($field['type']);

        if ($value === null || $value === '' || $value === []) {
            return $type?->isBoolean() ? (string) __('alfakit-forms::forms.answers.no') : null;
        }

        if (is_bool($value) || ($type?->isBoolean() && in_array($value, [0, 1, '0', '1'], true))) {
            return (string) __('alfakit-forms::forms.answers.'.((bool) $value ? 'yes' : 'no'));
        }

        if ($type === FieldType::File) {
            $files = is_array($value) ? $value : [$value];

            return implode(', ', array_map(static fn (mixed $path): string => is_string($path) ? basename($path) : '', array_filter($files, is_string(...))));
        }

        if ($type === FieldType::Rating && is_numeric($value)) {
            $max = (int) ($field['data']['max'] ?? 5);

            return ((int) $value).' / '.max(1, $max);
        }

        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) || $item instanceof Stringable ? (string) $item : (string) json_encode($item), $value));
        }

        return is_scalar($value) || $value instanceof Stringable ? (string) $value : (string) json_encode($value);
    }

    /**
     * The stored file paths of a file answer.
     *
     * @return array<int, string>
     */
    public static function files(mixed $value): array
    {
        $files = is_array($value) ? $value : [$value];

        return array_values(array_filter($files, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }
}
