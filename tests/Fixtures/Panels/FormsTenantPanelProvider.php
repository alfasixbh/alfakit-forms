<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitForms\FormsPlugin;

class FormsTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('forms-team')
            ->path('forms-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->plugin(FormsPlugin::make());
    }
}
