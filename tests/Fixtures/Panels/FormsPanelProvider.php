<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitForms\FormsPlugin;

class FormsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('forms-admin')
            ->path('forms-admin')
            ->login()
            ->middleware(['web'])
            ->databaseNotifications()
            ->plugin(FormsPlugin::make());
    }
}
