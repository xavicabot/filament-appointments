<?php

namespace XaviCabot\FilamentAppointments\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use XaviCabot\FilamentAppointments\FilamentAppointmentsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            FilamentAppointmentsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filament-appointments.timezone', 'Europe/Madrid');
        $app['config']->set('filament-appointments.google.cache_ttl_seconds', 120);
        $app['config']->set('filament-appointments.bookings.require_confirmation', false);
        $app['config']->set('filament-appointments.bookings.confirmation_ttl_hours', 24);
    }
}
