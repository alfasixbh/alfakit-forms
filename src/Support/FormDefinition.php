<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\AlfakitForms\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * The stored definition of a form: an ordered list of blocks (`['type' => …, 'data' =>
 * […]]`) as the builder saves them. It knows the answer fields, the pages (split at page
 * breaks), which fields other fields' rules depend on, and which fields are visible for
 * a given set of answers — the single source the public schema, validation, storage,
 * exports and emails are derived from.
 */
final class FormDefinition
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Answer keys a form can't use: they hold the captcha and the plugin's own state. */
    public const RESERVED_NAMES = ['captcha', 'form', 'token', 'submit'];

    /** @var array<int, array{type: string, data: array<string, mixed>}> */
    protected array $items;

    /** @var array<string, array<string, bool>> visibility maps by answers hash */
    protected array $visibilityCache = [];

    /**
     * @param  array<int|string, mixed>  $items
     */
    public function __construct(array $items)
    {
        $this->items = self::normalize($items);
    }

    // ---------------------------------------------------------------- Normalisation

    /**
     * Clean up builder state for storage: unknown blocks are dropped, every answer field
     * gets a unique, safe name (from its label when it has none), options are trimmed and
     * deduplicated, and rules only keep known operators and existing fields.
     *
     * @param  array<int|string, mixed>  $items
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public static function normalize(array $items): array
    {
        $normalized = [];
        $names = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item) || ! is_string($item['type'] ?? null)) {
                continue;
            }

            $type = FieldType::tryFrom($item['type']);

            if ($type === null) {
                continue;
            }

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];

            if ($type->isInput()) {
                $data['name'] = self::uniqueName(self::baseName($data, $index), $names);
                $names[] = $data['name'];
            }

            if ($type->hasOptions()) {
                $data['options'] = self::normalizeOptions($data['options'] ?? []);
            }

            $normalized[] = ['type' => $type->value, 'data' => $data];
        }

        // Rules may only point at answer fields of this form, never at the field itself.
        foreach ($normalized as $index => $item) {
            $normalized[$index]['data']['conditions'] = self::normalizeConditions(
                $item['data']['conditions'] ?? [],
                $names,
                is_string($item['data']['name'] ?? null) ? $item['data']['name'] : null,
            );
            $normalized[$index]['data']['condition_match'] = ($item['data']['condition_match'] ?? 'all') === 'any' ? 'any' : 'all';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function baseName(array $data, int $index): string
    {
        $name = is_string($data['name'] ?? null) ? Str::lower(trim($data['name'])) : '';

        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $source = $name !== '' ? $name : (is_string($data['label'] ?? null) ? $data['label'] : '');
            $name = Str::limit(trim((string) preg_replace('/_+/', '_', Str::slug(Str::ascii($source), '_')), '_'), 48, '');
        }

        if ($name === '' || preg_match('/^[a-z]/', $name) !== 1) {
            $name = 'field_'.($name === '' ? $index + 1 : $name);
        }

        return mb_substr($name, 0, 64);
    }

    /**
     * @param  array<int, string>  $taken
     */
    protected static function uniqueName(string $name, array $taken): string
    {
        $candidate = $name;
        $suffix = 2;

        while (in_array($candidate, $taken, true) || in_array($candidate, self::RESERVED_NAMES, true)) {
            $candidate = mb_substr($name, 0, 60).'_'.$suffix++;
        }

        return $candidate;
    }

    /**
     * @return array<int, string>
     */
    protected static function normalizeOptions(mixed $options): array
    {
        $clean = [];

        foreach (is_array($options) ? $options : [] as $option) {
            if (is_array($option)) {
                $option = $option['label'] ?? $option['value'] ?? reset($option);
            }

            if (! is_scalar($option)) {
                continue;
            }

            $option = mb_substr(trim((string) $option), 0, 255);

            if ($option !== '' && ! in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }

        return $clean;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, array{field: string, operator: string, value: string|null}>
     */
    protected static function normalizeConditions(mixed $conditions, array $names, ?string $self): array
    {
        $clean = [];

        foreach (is_array($conditions) ? $conditions : [] as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            if (! is_string($field) || ! in_array($field, $names, true) || $field === $self || ! in_array($operator, Conditions::OPERATORS, true)) {
                continue;
            }

            $value = $condition['value'] ?? null;

            $clean[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => in_array($operator, Conditions::VALUE_OPERATORS, true) && is_scalar($value) ? mb_substr(trim((string) $value), 0, 255) : null,
            ];
        }

        return $clean;
    }

    // ---------------------------------------------------------------- Structure

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * The answer fields, by name, in form order.
     *
     * @return array<string, array{type: string, data: array<string, mixed>}>
     */
    public function getFields(): array
    {
        $fields = [];

        foreach ($this->items as $item) {
            if (FieldType::from($item['type'])->isInput()) {
                $fields[(string) $item['data']['name']] = $item;
            }
        }

        return $fields;
    }

    /**
     * @return array{type: string, data: array<string, mixed>}|null
     */
    public function getField(string $name): ?array
    {
        return $this->getFields()[$name] ?? null;
    }

    /**
     * Field name => label, for rule pickers and exports.
     *
     * @return array<string, string>
     */
    public function getFieldLabels(): array
    {
        $labels = [];

        foreach ($this->getFields() as $name => $item) {
            $label = is_string($item['data']['label'] ?? null) && trim($item['data']['label']) !== '' ? trim($item['data']['label']) : $name;
            $labels[$name] = Str::limit($label, 80);
        }

        return $labels;
    }

    /**
     * The pages of the form, split at page breaks. A page break's label and description
     * title the page that follows it.
     *
     * @return array<int, array{label: string|null, description: string|null, items: array<int, array{type: string, data: array<string, mixed>, index: int}>}>
     */
    public function getPages(): array
    {
        $pages = [['label' => null, 'description' => null, 'items' => []]];

        foreach ($this->items as $index => $item) {
            if ($item['type'] === FieldType::PageBreak->value) {
                $pages[] = [
                    'label' => self::text($item['data']['label'] ?? null),
                    'description' => self::text($item['data']['description'] ?? null),
                    'items' => [],
                ];

                continue;
            }

            $pages[array_key_last($pages)]['items'][] = [...$item, 'index' => $index];
        }

        // Empty pages (two page breaks in a row, a trailing break) are skipped.
        return array_values(array_filter($pages, static fn (array $page): bool => $page['items'] !== []));
    }

    public function isMultiStep(): bool
    {
        return count($this->getPages()) > 1;
    }

    /**
     * The names of the fields that some rule looks at: the public form refreshes from the
     * server when they change.
     *
     * @return array<int, string>
     */
    public function getDependencies(): array
    {
        $names = [];

        foreach ($this->items as $item) {
            foreach ($item['data']['conditions'] ?? [] as $condition) {
                $names[] = $condition['field'];
            }
        }

        return array_values(array_unique($names));
    }

    // ---------------------------------------------------------------- Visibility

    /**
     * Which blocks are visible for these answers, by block key (`field:{name}` for
     * fields, `block:{index}` for headings and paragraphs). Blocks are evaluated in form
     * order: a field under a hidden heading is hidden, and a rule that looks at a hidden
     * field sees it as blank.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, bool>
     */
    public function getVisibility(array $answers): array
    {
        $hash = md5(serialize($answers));

        if (isset($this->visibilityCache[$hash])) {
            return $this->visibilityCache[$hash];
        }

        $visible = [];
        $sectionVisible = true;
        // The answers rules see: a hidden field's answer is blanked once it is evaluated.
        $effective = $answers;

        $answer = static function (string $name) use (&$effective): mixed {
            return $effective[$name] ?? null;
        };

        foreach ($this->items as $index => $item) {
            $type = FieldType::from($item['type']);

            if ($type === FieldType::PageBreak) {
                $sectionVisible = true;

                continue;
            }

            $passes = Conditions::passes($item['data']['conditions'] ?? [], (string) ($item['data']['condition_match'] ?? 'all'), $answer);

            if ($type === FieldType::Heading) {
                $sectionVisible = $passes;
                $visible['block:'.$index] = $passes;

                continue;
            }

            $isVisible = $sectionVisible && $passes;

            if ($type->isInput()) {
                $name = (string) $item['data']['name'];
                $visible['field:'.$name] = $isVisible;

                if (! $isVisible) {
                    $effective[$name] = null;
                }

                continue;
            }

            $visible['block:'.$index] = $isVisible;
        }

        if (count($this->visibilityCache) > 32) {
            $this->visibilityCache = [];
        }

        return $this->visibilityCache[$hash] = $visible;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function isFieldVisible(string $name, array $answers): bool
    {
        return $this->getVisibility($answers)['field:'.$name] ?? false;
    }

    /**
     * Keep only the answers of fields that exist and are visible for these answers: what
     * a submission stores.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function filterAnswers(array $answers): array
    {
        $visibility = $this->getVisibility($answers);
        $filtered = [];

        foreach (array_keys($this->getFields()) as $name) {
            if (($visibility['field:'.$name] ?? false) && array_key_exists($name, $answers)) {
                $filtered[$name] = $answers[$name];
            }
        }

        return $filtered;
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
