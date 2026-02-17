<?php

namespace XaviCabot\FilamentAppointments;

use Google\Client as GoogleClient;
use Illuminate\Support\ServiceProvider;
use XaviCabot\FilamentAppointments\Console\Commands\ExpirePendingAppointments;
use XaviCabot\FilamentAppointments\Services\AppointmentService;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarService;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;

class FilamentAppointmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/filament-appointments.php', 'filament-appointments');

        $this->app->singleton('filament-appointments.resolver', function () {
            $class = config('filament-appointments.resolver');
            return app($class);
        });

        $this->app->singleton(AppointmentService::class);

        $this->app->singleton(GoogleCalendarServiceInterface::class, function () {
            $client = new GoogleClient;
            $client->setClientId((string) config('services.google.client_id', env('GOOGLE_CLIENT_ID', '')));
            $client->setClientSecret((string) config('services.google.client_secret', env('GOOGLE_CLIENT_SECRET', '')));
            $client->setAccessType('offline');

            return new GoogleCalendarService($client);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/filament-appointments.php' => config_path('filament-appointments.php'),
        ], 'filament-appointments-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'filament-appointments-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/filament-appointments'),
        ], 'filament-appointments-views');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'filament-appointments');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'filament-appointments');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpirePendingAppointments::class,
            ]);
        }
    }
}
