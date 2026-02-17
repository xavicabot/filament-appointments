<?php

use Illuminate\Support\Facades\Route;
use XaviCabot\FilamentAppointments\Http\Controllers\AppointmentConfirmationController;
use XaviCabot\FilamentAppointments\Http\Controllers\SlotsController;
use XaviCabot\FilamentAppointments\Http\Controllers\GoogleOAuthController;

Route::middleware(['web'])
    ->prefix(config('filament-appointments.route_prefix'))
    ->group(function () {
        Route::get('/slots', [SlotsController::class, 'index'])->name('filament-appointments.slots');

        Route::get('/bookings/confirm/{booking}', [AppointmentConfirmationController::class, 'confirm'])
            ->name('filament-appointments.bookings.confirm');

        Route::get('/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('filament-appointments.google.redirect');
        Route::get('/google/callback', [GoogleOAuthController::class, 'callback'])->name('filament-appointments.google.callback');
        Route::post('/google/disconnect', [GoogleOAuthController::class, 'disconnect'])->name('filament-appointments.google.disconnect');
        Route::post('/google/sync-calendars', [GoogleOAuthController::class, 'syncCalendars'])->name('filament-appointments.google.sync-calendars');
    });
