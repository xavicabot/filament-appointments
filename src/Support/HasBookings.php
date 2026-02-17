<?php

namespace XaviCabot\FilamentAppointments\Support;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;

trait HasBookings
{
    public function getEmailForBooking(): string
    {
        return (string) $this->email;
    }

    public function getNameForBooking(): string
    {
        return (string) $this->name;
    }

    public function bookings(): MorphMany
    {
        return $this->morphMany(AppointmentBooking::class, 'client');
    }
}
