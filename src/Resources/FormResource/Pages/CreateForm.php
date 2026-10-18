<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Resources\FormResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitForms\Resources\FormResource;

class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;

    /**
     * The resource registered by the plugin (a subclass given to `resourceClass()`).
     */
    public static function getResource(): string
    {
        return FormResource::getPlugin()->getResourceClass();
    }
}
