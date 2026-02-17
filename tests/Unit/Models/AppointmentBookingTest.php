<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Models;

use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Support\SlotOwner;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    public function test_confirmed_scope(): void
    {
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'status' => 'cancelled',
        ]);

        $confirmed = AppointmentBooking::confirmed()->get();
        $this->assertCount(1, $confirmed);
        $this->assertSame('10:00', $confirmed->first()->start_time);
    }

    public function test_metadata_cast_as_array(): void
    {
        $booking = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
            'metadata' => ['notes' => 'Test booking', 'source' => 'web'],
        ]);

        $booking->refresh();

        $this->assertIsArray($booking->metadata);
        $this->assertSame('Test booking', $booking->metadata['notes']);
        $this->assertSame('web', $booking->metadata['source']);
    }

    public function test_pending_scope(): void
    {
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'status' => 'confirmed',
        ]);

        $pending = AppointmentBooking::pending()->get();
        $this->assertCount(1, $pending);
        $this->assertSame('10:00', $pending->first()->start_time);
    }

    public function test_active_scope(): void
    {
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'status' => 'confirmed',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '12:00',
            'end_time' => '12:30',
            'status' => 'cancelled',
        ]);

        $active = AppointmentBooking::active()->get();
        $this->assertCount(2, $active);
    }

    public function test_expired_scope(): void
    {
        // Pending created 25 hours ago — expired
        $expired = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);
        AppointmentBooking::where('id', $expired->id)
            ->update(['created_at' => now()->subHours(25)]);

        // Pending created 1 hour ago — not expired
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'status' => 'pending',
        ]);

        // Confirmed created 25 hours ago — not expired (not pending)
        $confirmed = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '12:00',
            'end_time' => '12:30',
            'status' => 'confirmed',
        ]);
        AppointmentBooking::where('id', $confirmed->id)
            ->update(['created_at' => now()->subHours(25)]);

        $expiredBookings = AppointmentBooking::expired(24)->get();
        $this->assertCount(1, $expiredBookings);
        $this->assertSame('10:00', $expiredBookings->first()->start_time);
    }

    public function test_for_owner_scope(): void
    {
        // Booking for user 1
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);

        // Booking for user 2
        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 2,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);

        $owner1 = new SlotOwner('user', 1);
        $owner2 = new SlotOwner('user', 2);

        $this->assertCount(1, AppointmentBooking::forOwner($owner1)->get());
        $this->assertCount(1, AppointmentBooking::forOwner($owner2)->get());
    }
}
