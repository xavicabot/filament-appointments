<?php

namespace XaviCabot\FilamentAppointments;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentAppointmentsPlugin implements Plugin
{
    public static function make(): static
    {
        return new static();
    }

    public function getId(): string
    {
        return 'filament-appointments';
    }

    public function register(Panel $panel): void
    {
        if (! config('filament-appointments.register_resources')) {
            return;
        }

        $panel->resources([
            \XaviCabot\FilamentAppointments\Filament\Resources\AppointmentRuleResource::class,
            \XaviCabot\FilamentAppointments\Filament\Resources\AppointmentBlockResource::class,
            \XaviCabot\FilamentAppointments\Filament\Resources\CalendarConnectionResource::class,
            \XaviCabot\FilamentAppointments\Filament\Resources\AppointmentBookingResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
