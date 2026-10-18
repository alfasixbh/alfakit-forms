<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Resources\FormResource\Pages;

use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\AlfakitForms\Resources\FormResource;
use AlfaSix\AlfakitForms\Widgets\FormStatsWidget;

/**
 * A form's stats (views, submissions, conversion, spam) and its submissions.
 */
class ViewForm extends ViewRecord
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
            $resource::getOpenAction(),
            $resource::getPreviewAction(),
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [FormStatsWidget::class];
    }
}
