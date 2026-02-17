<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Commands;

use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class ExpirePendingAppointmentsTest extends TestCase
{
    public function test_cancels_expired_pending_bookings(): void
    {
        $booking = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);
        AppointmentBooking::where('id', $booking->id)
            ->update(['created_at' => now()->subHours(25)]);

        $this->artisan('fa:expire-appointments')->assertSuccessful();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
    }

    public function test_preserves_recent_pending_bookings(): void
    {
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);

        $this->artisan('fa:expire-appointments')->assertSuccessful();

        $this->assertSame(1, AppointmentBooking::pending()->count());
    }

    public function test_preserves_confirmed_bookings(): void
    {
        $booking = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);
        AppointmentBooking::where('id', $booking->id)
            ->update(['created_at' => now()->subHours(25)]);

        $this->artisan('fa:expire-appointments')->assertSuccessful();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
    }
}
