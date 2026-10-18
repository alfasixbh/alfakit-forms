<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Enums;

/**
 * Whether a form accepts submissions right now, and why not.
 */
enum FormStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Full = 'full';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return (string) __('alfakit-forms::forms.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::Open => 'success',
            self::Full => 'warning',
            self::Closed => 'danger',
        };
    }

    public function acceptsSubmissions(): bool
    {
        return $this === self::Open;
    }
}
