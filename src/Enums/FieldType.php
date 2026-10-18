<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Enums;

/**
 * The block types of the form builder. Inputs collect an answer stored under the field's
 * `name`; layout blocks (heading, paragraph, page break) only shape the public form.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';
    case Number = 'number';
    case Select = 'select';
    case Radio = 'radio';
    case CheckboxList = 'checkbox_list';
    case Checkbox = 'checkbox';
    case Toggle = 'toggle';
    case Date = 'date';
    case File = 'file';
    case Rating = 'rating';
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case PageBreak = 'page_break';

    public function isInput(): bool
    {
        return ! in_array($this, [self::Heading, self::Paragraph, self::PageBreak], true);
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::Radio, self::CheckboxList], true);
    }

    /**
     * Inputs whose answer is a list (several options or files).
     */
    public function isMultiValued(bool $multiple = false): bool
    {
        return $this === self::CheckboxList || ($multiple && in_array($this, [self::Select, self::File], true));
    }

    /**
     * Inputs that hold free text, so their placeholder and length limits apply.
     */
    public function isTextual(): bool
    {
        return in_array($this, [self::Text, self::Textarea, self::Email, self::Phone, self::Url], true);
    }

    public function isBoolean(): bool
    {
        return in_array($this, [self::Checkbox, self::Toggle], true);
    }

    public function getLabel(): string
    {
        return (string) __('alfakit-forms::forms.types.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Text => 'type',
            self::Textarea => 'align-left',
            self::Email => 'mail',
            self::Phone => 'phone',
            self::Url => 'link',
            self::Number => 'hash',
            self::Select => 'chevrons-up-down',
            self::Radio => 'circle-dot',
            self::CheckboxList => 'list-checks',
            self::Checkbox => 'square-check',
            self::Toggle => 'toggle-right',
            self::Date => 'calendar',
            self::File => 'paperclip',
            self::Rating => 'star',
            self::Heading => 'heading',
            self::Paragraph => 'pilcrow',
            self::PageBreak => 'separator-horizontal',
        };
    }

    public function getGroup(): string
    {
        return (string) __('alfakit-forms::forms.groups.'.match ($this) {
            self::Text, self::Textarea, self::Email, self::Phone, self::Url, self::Number => 'text',
            self::Select, self::Radio, self::CheckboxList, self::Checkbox, self::Toggle, self::Rating => 'choice',
            self::Date, self::File => 'other',
            self::Heading, self::Paragraph, self::PageBreak => 'layout',
        });
    }
}
