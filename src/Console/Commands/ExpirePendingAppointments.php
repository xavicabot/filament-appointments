<?php

namespace XaviCabot\FilamentAppointments\Console\Commands;

use Illuminate\Console\Command;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;

class ExpirePendingAppointments extends Command
{
    protected $signature = 'fa:expire-appointments';

    protected $description = 'Cancel pending bookings that have exceeded the confirmation TTL';

    public function handle(): int
    {
        $ttlHours = (int) config('filament-appointments.bookings.confirmation_ttl_hours', 24);

        $count = AppointmentBooking::expired($ttlHours)->update(['status' => 'cancelled']);

        $this->info("Cancelled {$count} expired pending booking(s).");

        return self::SUCCESS;
    }
}
