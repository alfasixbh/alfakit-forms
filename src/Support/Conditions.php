<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use Closure;
use Stringable;

/**
 * Conditional visibility rules ("show this field when *Topic* equals *Support*"),
 * evaluated in PHP only. The public form re-renders from the server when a field that a
 * rule depends on changes, so the browser never evaluates a rule itself.
 */
final class Conditions
{
    public const OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'filled',
        'blank',
        'greater_than',
        'less_than',
    ];

    /** Operators that compare with a value (the others look at the answer alone). */
    public const VALUE_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than'];

    /** The operator a new rule starts with. */
    public const DEFAULT_OPERATOR = 'equals';

    /**
     * Whether a rule with this operator needs a value. A rule that was just added has no
     * operator in its state yet: it shows (and uses) the default, which needs one.
     */
    public static function needsValue(mixed $operator): bool
    {
        return in_array($operator ?? self::DEFAULT_OPERATOR, self::VALUE_OPERATORS, true);
    }

    /**
     * @return array<string, string>
     */
    public static function operatorOptions(): array
    {
        $options = [];

        foreach (self::OPERATORS as $operator) {
            $options[$operator] = (string) __('alfakit-forms::forms.operators.'.$operator);
        }

        return $options;
    }

    /**
     * Whether the rules pass: all of them (`$match = 'all'`) or at least one (`'any'`).
     * No rules always pass.
     *
     * @param  array<int, array{field: string, operator: string, value: string|null}>  $conditions
     * @param  Closure(string): mixed  $answer  the current answer of a field, by name
     */
    public static function passes(array $conditions, string $match, Closure $answer): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $passes = self::check($answer($condition['field']), $condition['operator'], $condition['value']);

            if ($match === 'any' && $passes) {
                return true;
            }

            if ($match !== 'any' && ! $passes) {
                return false;
            }
        }

        return $match !== 'any';
    }

    public static function check(mixed $answer, string $operator, ?string $expected): bool
    {
        $values = self::values($answer);
        $expected = self::normalize($expected);

        return match ($operator) {
            'equals' => in_array($expected, $values, true),
            'not_equals' => ! in_array($expected, $values, true),
            'contains' => self::contains($values, $expected),
            'not_contains' => ! self::contains($values, $expected),
            'filled' => $values !== [],
            'blank' => $values === [],
            'greater_than' => self::compare($values, $expected) === 1,
            'less_than' => self::compare($values, $expected) === -1,
            default => false,
        };
    }

    /**
     * An answer as a list of normalised strings (a checkbox list or multiple select has
     * several; a blank answer has none).
     *
     * @return array<int, string>
     */
    protected static function values(mixed $answer): array
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return [];
        }

        if (is_array($answer)) {
            $values = [];

            foreach ($answer as $value) {
                if (is_scalar($value) || $value instanceof Stringable) {
                    $values[] = self::normalize(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                }
            }

            return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        }

        if (is_bool($answer)) {
            // An unticked checkbox is "blank" for `filled` / `blank`, and "0" for equals.
            return $answer ? ['1'] : ['0'];
        }

        return is_scalar($answer) || $answer instanceof Stringable ? [self::normalize((string) $answer)] : [];
    }

    protected static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            'true', 'yes', 'on' => '1',
            'false', 'no', 'off' => '0',
            default => $value,
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    protected static function contains(array $values, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        foreach ($values as $value) {
            if (str_contains($value, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * -1, 0 or 1 when the (first) answer and the expected value are both numbers or both
     * dates; null when they can't be compared.
     *
     * @param  array<int, string>  $values
     */
    protected static function compare(array $values, string $expected): ?int
    {
        $value = $values[0] ?? null;

        if ($value === null || $expected === '') {
            return null;
        }

        if (is_numeric($value) && is_numeric($expected)) {
            return (float) $value <=> (float) $expected;
        }

        $left = strtotime($value);
        $right = strtotime($expected);

        return $left !== false && $right !== false ? $left <=> $right : null;
    }
}
