<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitForms\AlfakitFormsServiceProvider;
use AlfaSix\AlfakitForms\Tests\Fixtures\Models\FormsUser;
use AlfaSix\AlfakitForms\Tests\Fixtures\Panels\FormsPanelProvider;
use AlfaSix\AlfakitForms\Tests\Fixtures\Panels\FormsTenantPanelProvider;

abstract class TestCase extends AlfakitTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitFormsServiceProvider::class,
            FormsPanelProvider::class,
            FormsTenantPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', FormsUser::class);
        $app['config']->set('alfakit-forms.spam.min_seconds', 0);
        $app['config']->set('mail.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/alfakit/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }
}
