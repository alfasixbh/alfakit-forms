<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\AlfakitForms\Enums\FieldType;
use Closure;
use Illuminate\Support\Str;

/**
 * The visual builder of the Forms resource: one Builder block per field type, each with
 * the field's label, answer key, help text, placeholder, required flag, width, the
 * validation options that fit its type and its visibility rules.
 *
 * Admins only ever choose values (a length, a format by name, an operator); none of it
 * is used as a validation rule string or a regular expression.
 */
final class BuilderBlocks
{
    /**
     * @param  array<int, FieldType>|null  $types
     */
    public static function make(string $name = 'fields', ?array $types = null): Builder
    {
        $types ??= FieldType::cases();

        return Builder::make($name)
            ->hiddenLabel()
            ->blocks(array_map(self::block(...), $types))
            ->blockPickerColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->searchable()
            ->blockNumbers(false)
            ->collapsible()
            ->cloneable()
            ->blockActionsMenu()
            ->addActionLabel((string) __('alfakit-forms::forms.builder.add'))
            ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                $names = [];

                foreach (is_array($value) ? $value : [] as $item) {
                    $name = is_array($item) && is_array($item['data'] ?? null) ? ($item['data']['name'] ?? null) : null;

                    if (! is_string($name) || $name === '') {
                        continue;
                    }

                    if (in_array($name, $names, true)) {
                        $fail(__('alfakit-forms::forms.builder.validation.duplicate_name', ['name' => $name]));

                        return;
                    }

                    $names[] = $name;
                }
            });
    }

    public static function block(FieldType $type): Block
    {
        return Block::make($type->value)
            ->label($type->getLabel())
            // The question after the field type, isolated so it reads right in either direction.
            ->summary(static function (?array $state): ?string {
                return is_array($state) && is_string($state['label'] ?? null) && trim($state['label']) !== '' ? Str::limit(trim($state['label']), 60) : null;
            })
            ->icon($type->getIcon())
            ->group($type->getGroup())
            ->columns(['default' => 1, 'md' => 2])
            ->schema(match (true) {
                $type === FieldType::PageBreak => self::pageBreak(),
                $type === FieldType::Heading => self::heading(),
                $type === FieldType::Paragraph => self::paragraph(),
                default => self::input($type),
            });
    }

    /**
     * @return array<Component>
     */
    protected static function input(FieldType $type): array
    {
        return [
            TextInput::make('label')
                ->label(__('alfakit-forms::forms.builder.label'))
                ->required()
                ->maxLength(255)
                ->live(onBlur: true),
            TextInput::make('name')
                ->label(__('alfakit-forms::forms.builder.name'))
                ->helperText(__('alfakit-forms::forms.builder.name_help'))
                ->placeholder(__('alfakit-forms::forms.builder.name_placeholder'))
                ->regex(FormDefinition::NAME_PATTERN)
                ->notIn(FormDefinition::RESERVED_NAMES)
                ->maxLength(64),
            TextInput::make('help')
                ->label(__('alfakit-forms::forms.builder.help'))
                ->maxLength(500),
            ...($type->isTextual() || in_array($type, [FieldType::Number, FieldType::Select, FieldType::Date], true) ? [
                TextInput::make('placeholder')
                    ->label(__('alfakit-forms::forms.builder.placeholder'))
                    ->maxLength(255),
            ] : []),
            ...self::options($type),
            Grid::make(['default' => 1, 'sm' => 2])
                ->columnSpanFull()
                ->schema([
                    Toggle::make('required')
                        ->label($type->isBoolean() ? __('alfakit-forms::forms.builder.must_accept') : __('alfakit-forms::forms.builder.required')),
                    ToggleButtons::make('width')
                        ->label(__('alfakit-forms::forms.builder.width'))
                        ->options([
                            'full' => __('alfakit-forms::forms.builder.widths.full'),
                            'half' => __('alfakit-forms::forms.builder.widths.half'),
                        ])
                        ->default('full')
                        ->inline()
                        ->grouped(),
                ]),
            ...self::validation($type),
            self::logic(),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function options(FieldType $type): array
    {
        if (! $type->hasOptions()) {
            return [];
        }

        return [
            Repeater::make('options')
                ->label(__('alfakit-forms::forms.builder.options'))
                ->simple(TextInput::make('option')->required()->maxLength(255))
                ->minItems(1)
                ->maxItems(200)
                ->reorderable()
                ->addActionLabel((string) __('alfakit-forms::forms.builder.add_option'))
                ->defaultItems(2)
                ->columnSpanFull()
                ->required(),
            ...($type === FieldType::Select ? [
                Toggle::make('multiple')->label(__('alfakit-forms::forms.builder.multiple')),
            ] : []),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function validation(FieldType $type): array
    {
        $fields = match ($type) {
            FieldType::Text, FieldType::Textarea => [
                self::integerInput('min_length', 'min_length', 0, 65535),
                self::integerInput('max_length', 'max_length', 1, 65535),
                ...($type === FieldType::Text ? [
                    Select::make('format')
                        ->label(__('alfakit-forms::forms.builder.format'))
                        ->helperText(__('alfakit-forms::forms.builder.format_help'))
                        ->options(fn (): array => Formats::options())
                        ->in(fn (): array => array_keys(Formats::all()))
                        ->placeholder(__('alfakit-forms::forms.builder.format_any')),
                ] : [
                    self::integerInput('rows', 'rows', 2, 12),
                ]),
            ],
            FieldType::Email, FieldType::Phone, FieldType::Url => [
                self::integerInput('max_length', 'max_length', 1, 2048),
            ],
            FieldType::Number => [
                TextInput::make('min')->label(__('alfakit-forms::forms.builder.min'))->numeric(),
                TextInput::make('max')->label(__('alfakit-forms::forms.builder.max'))->numeric(),
                Toggle::make('integer')->label(__('alfakit-forms::forms.builder.integer')),
            ],
            FieldType::CheckboxList => [
                self::integerInput('min_items', 'min_items', 0, 200),
                self::integerInput('max_items', 'max_items', 1, 200),
            ],
            FieldType::Date => [
                DatePicker::make('min_date')->label(__('alfakit-forms::forms.builder.min_date')),
                DatePicker::make('max_date')->label(__('alfakit-forms::forms.builder.max_date'))->minDate(fn (Get $get): mixed => $get('min_date') ?: null),
                Toggle::make('include_time')->label(__('alfakit-forms::forms.builder.include_time')),
            ],
            FieldType::File => [
                Select::make('file_types')
                    ->label(__('alfakit-forms::forms.builder.file_types'))
                    ->options([
                        'images' => __('alfakit-forms::forms.builder.file_type_options.images'),
                        'pdf' => __('alfakit-forms::forms.builder.file_type_options.pdf'),
                        'documents' => __('alfakit-forms::forms.builder.file_type_options.documents'),
                    ])
                    ->placeholder(__('alfakit-forms::forms.builder.file_type_options.any')),
                self::integerInput('max_size', 'max_size', 1, max(1, (int) config('alfakit-forms.uploads.max_size', 10240)))
                    ->suffix('KB'),
                Toggle::make('multiple')->label(__('alfakit-forms::forms.builder.multiple_files'))->live(),
                self::integerInput('max_files', 'max_files', 1, max(1, (int) config('alfakit-forms.uploads.max_files', 10)))
                    ->visible(fn (Get $get): bool => (bool) $get('multiple')),
            ],
            FieldType::Rating => [
                self::integerInput('max', 'rating_max', 2, 10)->default(5),
            ],
            default => [],
        };

        if ($fields === []) {
            return [];
        }

        return [
            Section::make(__('alfakit-forms::forms.builder.validation.heading'))
                ->columns(['default' => 1, 'sm' => 2])
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->compact()
                ->schema($fields),
        ];
    }

    protected static function integerInput(string $name, string $label, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label(__('alfakit-forms::forms.builder.'.$label))
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max);
    }

    /**
     * "Show this block when…" rules. The field picker lists the answer fields of the form
     * being built (with the keys they will be saved under).
     */
    protected static function logic(): Section
    {
        return Section::make(__('alfakit-forms::forms.builder.logic.heading'))
            ->description(__('alfakit-forms::forms.builder.logic.description'))
            ->columnSpanFull()
            ->collapsible()
            ->collapsed()
            ->compact()
            ->schema([
                ToggleButtons::make('condition_match')
                    ->label(__('alfakit-forms::forms.builder.logic.match'))
                    ->options([
                        'all' => __('alfakit-forms::forms.builder.logic.match_all'),
                        'any' => __('alfakit-forms::forms.builder.logic.match_any'),
                    ])
                    ->default('all')
                    ->inline()
                    ->grouped(),
                Repeater::make('conditions')
                    ->hiddenLabel()
                    ->addActionLabel((string) __('alfakit-forms::forms.builder.logic.add'))
                    ->defaultItems(0)
                    ->maxItems(20)
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('field')
                            ->label(__('alfakit-forms::forms.builder.logic.field'))
                            ->options(fn (Get $get): array => self::fieldOptions($get))
                            ->required(),
                        Select::make('operator')
                            ->label(__('alfakit-forms::forms.builder.logic.operator'))
                            ->options(Conditions::operatorOptions())
                            ->in(Conditions::OPERATORS)
                            ->default('equals')
                            ->required()
                            ->live(),
                        TextInput::make('value')
                            ->label(__('alfakit-forms::forms.builder.logic.value'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => in_array($get('operator'), Conditions::VALUE_OPERATORS, true)),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function fieldOptions(Get $get): array
    {
        $items = $get('fields', isAbsolute: true);

        return (new FormDefinition(is_array($items) ? $items : []))->getFieldLabels();
    }

    /**
     * @return array<Component>
     */
    protected static function heading(): array
    {
        return [
            TextInput::make('label')
                ->label(__('alfakit-forms::forms.builder.heading'))
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->columnSpanFull(),
            Textarea::make('description')
                ->label(__('alfakit-forms::forms.builder.description'))
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
            self::logic(),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function paragraph(): array
    {
        return [
            Textarea::make('content')
                ->label(__('alfakit-forms::forms.builder.content'))
                ->required()
                ->rows(3)
                ->maxLength(5000)
                ->columnSpanFull(),
            self::logic(),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function pageBreak(): array
    {
        return [
            TextInput::make('label')
                ->label(__('alfakit-forms::forms.builder.page_title'))
                ->helperText(__('alfakit-forms::forms.builder.page_title_help'))
                ->maxLength(120)
                ->live(onBlur: true),
            TextInput::make('description')
                ->label(__('alfakit-forms::forms.builder.description'))
                ->maxLength(255),
        ];
    }
}
