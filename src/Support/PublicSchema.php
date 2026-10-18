<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Support;

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\AlfakitForms\Enums\FieldType;
use AlfaSix\AlfakitForms\Models\Form;

/**
 * Turns a stored form definition into AlfaKit schema components. The same components
 * render the public form and validate every submission, so a rule exists exactly once:
 * in the definition the admin saved.
 */
final class PublicSchema
{
    /** Accepted MIME types per file type an admin can choose. SVG and HTML are never accepted. */
    public const FILE_TYPES = [
        'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'pdf' => ['application/pdf'],
        'documents' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'text/csv',
        ],
    ];

    public const DEFAULT_TEXT_MAX_LENGTH = 255;

    public const DEFAULT_TEXTAREA_MAX_LENGTH = 5000;

    public function __construct(protected Form $form) {}

    /**
     * The form's components: one wizard step per page, or the fields of the only page.
     * `$append` (the captcha) goes at the end of the last page.
     *
     * @param  array<Component>  $append
     * @return array<Component>
     */
    public function components(array $append = []): array
    {
        $definition = $this->form->definition();
        $pages = $definition->getPages();

        if (count($pages) <= 1) {
            return [...$this->pageComponents($pages[0]['items'] ?? []), ...$append];
        }

        $last = array_key_last($pages);

        $steps = [];

        foreach ($pages as $number => $page) {
            $label = $page['label'] ?? ($number === 0 ? self::text($this->form->getSetting('first_page_label')) : null);

            $steps[] = Step::make($label ?? (string) __('alfakit-forms::forms.public.step', ['number' => $number + 1]))
                ->key('page_'.($number + 1))
                ->description($page['description'] ?? ($number === 0 ? self::text($this->form->getSetting('first_page_description')) : null))
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([...$this->pageComponents($page['items']), ...($number === $last ? $append : [])]);
        }

        return [
            Wizard::make($steps)
                ->key('pages')
                ->nextActionLabel((string) __('alfakit-forms::forms.public.next'))
                ->previousActionLabel((string) __('alfakit-forms::forms.public.previous')),
        ];
    }

    /**
     * The fields of one page; a heading groups the fields after it (up to the next
     * heading) in a section, so its rules hide them together.
     *
     * @param  array<int, array{type: string, data: array<string, mixed>, index: int}>  $items
     * @return array<Component>
     */
    protected function pageComponents(array $items): array
    {
        $components = [];
        $section = null;
        $sectionItems = [];

        foreach ($items as $item) {
            $type = FieldType::from($item['type']);

            if ($type === FieldType::Heading) {
                if ($section !== null) {
                    $components[] = $section->schema($sectionItems);
                }

                $section = $this->heading($item);
                $sectionItems = [];

                continue;
            }

            $component = $this->component($item);

            if ($component === null) {
                continue;
            }

            if ($section !== null) {
                $sectionItems[] = $component;
            } else {
                $components[] = $component;
            }
        }

        if ($section !== null) {
            $components[] = $section->schema($sectionItems);
        }

        return $components;
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, index: int}  $item
     */
    protected function heading(array $item): Section
    {
        $key = 'block:'.$item['index'];

        return Section::make(self::text($item['data']['label'] ?? null))
            ->key('block_'.$item['index'])
            ->description(self::text($item['data']['description'] ?? null))
            ->columns(['default' => 1, 'sm' => 2])
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => $this->isVisible($key, $get))
            ->compact();
    }

    /**
     * @param  array{type: string, data: array<string, mixed>, index: int}  $item
     */
    protected function component(array $item): ?Component
    {
        $type = FieldType::from($item['type']);
        $data = $item['data'];

        if ($type === FieldType::Paragraph) {
            $content = self::text($data['content'] ?? null);

            if ($content === null) {
                return null;
            }

            $key = 'block:'.$item['index'];

            return Text::make($content)
                ->key('block_'.$item['index'])
                ->color('gray')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->isVisible($key, $get));
        }

        if (! $type->isInput()) {
            return null;
        }

        $name = (string) $data['name'];
        $field = $this->field($type, $name, $data);
        $key = 'field:'.$name;

        $field
            ->label(self::text($data['label'] ?? null) ?? $name)
            ->helperText(self::text($data['help'] ?? null))
            ->columnSpan(($data['width'] ?? 'full') === 'half' ? 1 : 'full')
            ->visible(fn (Get $get): bool => $this->isVisible($key, $get));

        if ((bool) ($data['required'] ?? false)) {
            $field instanceof Checkbox || $field instanceof Toggle ? $field->accepted() : $field->required();
        }

        if (in_array($name, $this->form->definition()->getDependencies(), true)) {
            $type->isTextual() || $type === FieldType::Number ? $field->live(onBlur: true) : $field->live();
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function field(FieldType $type, string $name, array $data): Field
    {
        $placeholder = self::text($data['placeholder'] ?? null);
        $options = array_combine($data['options'] ?? [], $data['options'] ?? []);

        return match ($type) {
            FieldType::Text => $this->formatted($this->textInput(TextInput::make($name), $data, $placeholder), $data),
            FieldType::Email => $this->textInput(TextInput::make($name)->email()->autocomplete('email'), $data, $placeholder),
            FieldType::Phone => $this->textInput(TextInput::make($name)->tel()->autocomplete('tel'), [...$data, 'max_length' => min(32, self::int($data['max_length'] ?? null) ?? 32)], $placeholder)
                ->regex('/^\+?[0-9\s().-]{5,32}$/'),
            FieldType::Url => $this->textInput(TextInput::make($name)->url()->autocomplete('url'), [...$data, 'max_length' => min(2048, self::int($data['max_length'] ?? null) ?? 2048)], $placeholder)
                ->rule('starts_with:http://,https://'),
            FieldType::Textarea => Textarea::make($name)
                ->placeholder($placeholder)
                ->rows(max(2, min(12, self::int($data['rows'] ?? null) ?? 4)))
                ->minLength(self::int($data['min_length'] ?? null))
                ->maxLength(min(65535, self::int($data['max_length'] ?? null) ?? self::DEFAULT_TEXTAREA_MAX_LENGTH)),
            FieldType::Number => TextInput::make($name)
                ->numeric()
                ->integer((bool) ($data['integer'] ?? false))
                ->placeholder($placeholder)
                ->minValue(self::number($data['min'] ?? null))
                ->maxValue(self::number($data['max'] ?? null)),
            FieldType::Select => Select::make($name)
                ->options($options)
                ->multiple((bool) ($data['multiple'] ?? false))
                ->searchable(count($options) > 10)
                ->placeholder($placeholder),
            FieldType::Radio => Radio::make($name)->options($options),
            FieldType::CheckboxList => CheckboxList::make($name)
                ->options($options)
                ->rule(($min = self::int($data['min_items'] ?? null)) !== null && $min > 0 ? 'min:'.$min : null)
                ->rule(($max = self::int($data['max_items'] ?? null)) !== null && $max > 0 ? 'max:'.$max : null),
            FieldType::Checkbox => Checkbox::make($name)->default(false),
            FieldType::Toggle => Toggle::make($name)->default(false),
            FieldType::Date => ((bool) ($data['include_time'] ?? false) ? DateTimePicker::make($name)->seconds(false) : DatePicker::make($name))
                ->placeholder($placeholder)
                ->minDate(self::text($data['min_date'] ?? null))
                ->maxDate(self::text($data['max_date'] ?? null)),
            FieldType::File => $this->fileUpload(FileUpload::make($name), $data),
            FieldType::Rating => $this->rating(ToggleButtons::make($name), $data),
            default => TextInput::make($name),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function textInput(TextInput $input, array $data, ?string $placeholder): TextInput
    {
        return $input
            ->placeholder($placeholder)
            ->minLength(self::int($data['min_length'] ?? null))
            ->maxLength(min(self::DEFAULT_TEXT_MAX_LENGTH * 8, self::int($data['max_length'] ?? null) ?? self::DEFAULT_TEXT_MAX_LENGTH));
    }

    /**
     * The admin picks a format by name; the pattern comes from the whitelist.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatted(TextInput $input, array $data): TextInput
    {
        $pattern = Formats::pattern(is_string($data['format'] ?? null) ? $data['format'] : null);

        return $pattern === null ? $input : $input->regex($pattern);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fileUpload(FileUpload $upload, array $data): FileUpload
    {
        $types = is_string($data['file_types'] ?? null) && isset(self::FILE_TYPES[$data['file_types']])
            ? self::FILE_TYPES[$data['file_types']]
            : array_values(array_unique([...self::FILE_TYPES['images'], ...self::FILE_TYPES['documents']]));
        $limit = max(1, (int) config('alfakit-forms.uploads.max_size', 10240));
        $maxFiles = max(1, (int) config('alfakit-forms.uploads.max_files', 10));
        $multiple = (bool) ($data['multiple'] ?? false);

        return $upload
            ->disk((string) config('alfakit-forms.uploads.disk', 'local'))
            ->directory(trim((string) config('alfakit-forms.uploads.directory', 'alfakit-forms'), '/').'/'.$this->form->getKey())
            ->visibility('private')
            ->acceptedFileTypes($types)
            ->maxSize(min($limit, self::int($data['max_size'] ?? null) ?? $limit))
            ->multiple($multiple)
            ->maxFiles($multiple ? min($maxFiles, self::int($data['max_files'] ?? null) ?? $maxFiles) : null)
            ->previewable(false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function rating(ToggleButtons $buttons, array $data): ToggleButtons
    {
        $max = max(2, min(10, self::int($data['max'] ?? null) ?? 5));
        $options = [];
        $icons = [];

        foreach (range(1, $max) as $value) {
            $options[$value] = (string) $value;
            $icons[$value] = 'star';
        }

        return $buttons->options($options)->icons($icons)->inline()->grouped();
    }

    protected function isVisible(string $key, Get $get): bool
    {
        $state = $get('', isAbsolute: true);

        return $this->form->definition()->getVisibility(is_array($state) ? $state : [])[$key] ?? true;
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    protected static function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}
