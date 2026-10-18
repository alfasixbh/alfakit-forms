<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Events;

use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A submission was stored (never dispatched for spam). Listen to it to push answers to a
 * CRM, a webhook or a queue.
 */
class FormSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly Form $form,
        public readonly FormSubmission $submission,
    ) {}
}
