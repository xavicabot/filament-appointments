<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Support;

use Mockery;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;
use XaviCabot\FilamentAppointments\Support\SlotOwner;
use XaviCabot\FilamentAppointments\Support\SlotResolver;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class SlotResolverBookingTest extends TestCase
{
    private SlotResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(6, 0, 0));

        $mock = Mockery::mock(GoogleCalendarServiceInterface::class);
        $this->app->instance(GoogleCalendarServiceInterface::class, $mock);

        $this->resolver = new SlotResolver;
    }

    private function owner(): SlotOwner
    {
        return new SlotOwner('user', 1);
    }

    private function createRule(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1, // Monday
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);
    }

    public function test_no_bookings_all_slots_enabled(): void
    {
        $this->createRule();

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $this->assertCount(6, $result);

        foreach ($result as $slot) {
            $this->assertFalse($slot['disabled']);
            $this->assertNull($slot['reason']);
        }
    }

    public function test_booked_slot_is_disabled(): void
    {
        $this->createRule();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $booked = collect($result)->firstWhere('value', '10:00');
        $this->assertTrue($booked['disabled']);
        $this->assertSame('booked', $booked['reason']);

        // Adjacent slots should still be enabled
        $before = collect($result)->firstWhere('value', '09:30');
        $this->assertFalse($before['disabled']);

        $after = collect($result)->firstWhere('value', '10:30');
        $this->assertFalse($after['disabled']);
    }

    public function test_cancelled_booking_does_not_disable(): void
    {
        $this->createRule();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'cancelled',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $slot = collect($result)->firstWhere('value', '10:00');
        $this->assertFalse($slot['disabled']);
        $this->assertNull($slot['reason']);
    }

    public function test_block_takes_priority_over_booked(): void
    {
        $this->createRule();

        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $slot = collect($result)->firstWhere('value', '10:00');
        $this->assertTrue($slot['disabled']);
        $this->assertSame('blocked', $slot['reason']); // block wins over booked
    }

    public function test_pending_booking_disables_slot(): void
    {
        $this->createRule();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $pending = collect($result)->firstWhere('value', '10:00');
        $this->assertTrue($pending['disabled']);
        $this->assertSame('pending', $pending['reason']);
    }

    public function test_multiple_bookings_disable_multiple_slots(): void
    {
        $this->createRule();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => 'confirmed',
        ]);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'status' => 'confirmed',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $bookedSlots = collect($result)->where('reason', 'booked')->pluck('value')->all();
        $this->assertContains('09:00', $bookedSlots);
        $this->assertContains('11:00', $bookedSlots);
        $this->assertCount(2, $bookedSlots);

        // Other slots remain enabled
        $enabled = collect($result)->where('disabled', false);
        $this->assertCount(4, $enabled);
    }
}
