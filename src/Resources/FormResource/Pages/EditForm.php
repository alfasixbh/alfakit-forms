<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Resources\FormResource\Pages;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitForms\Resources\FormResource;

/**
 * The builder and the settings of a form. Submissions live on the view page.
 */
class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    public static function getResource(): string
    {
        return FormResource::getPlugin()->getResourceClass();
    }

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            ViewAction::make(),
            $resource::getPreviewAction(),
            $resource::getOpenAction(),
            DeleteAction::make(),
        ];
    }

    public function getAllRelationManagers(): array
    {
        return [];
    }
}
