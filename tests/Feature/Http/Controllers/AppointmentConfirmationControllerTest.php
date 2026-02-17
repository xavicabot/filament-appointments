<?php

namespace XaviCabot\FilamentAppointments\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\URL;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class AppointmentConfirmationControllerTest extends TestCase
{
    private function createPendingBooking(): AppointmentBooking
    {
        return AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-20',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'pending',
        ]);
    }

    public function test_signed_url_confirms_pending_booking(): void
    {
        $booking = $this->createPendingBooking();

        $url = URL::temporarySignedRoute(
            'filament-appointments.bookings.confirm',
            now()->addHours(24),
            ['booking' => $booking->id],
        );

        $response = $this->get($url);

        $response->assertOk();
        $response->assertViewIs('filament-appointments::bookings.confirmed');

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $booking = $this->createPendingBooking();

        $url = route('filament-appointments.bookings.confirm', ['booking' => $booking->id]);

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_expired_signature_returns_403(): void
    {
        $booking = $this->createPendingBooking();

        $url = URL::temporarySignedRoute(
            'filament-appointments.bookings.confirm',
            now()->subMinute(),
            ['booking' => $booking->id],
        );

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_already_confirmed_shows_already_processed(): void
    {
        $booking = $this->createPendingBooking();
        $booking->update(['status' => 'confirmed']);

        $url = URL::temporarySignedRoute(
            'filament-appointments.bookings.confirm',
            now()->addHours(24),
            ['booking' => $booking->id],
        );

        $response = $this->get($url);

        $response->assertOk();
        $response->assertViewHas('alreadyProcessed', true);
    }

    public function test_cancelled_booking_shows_already_processed(): void
    {
        $booking = $this->createPendingBooking();
        $booking->update(['status' => 'cancelled']);

        $url = URL::temporarySignedRoute(
            'filament-appointments.bookings.confirm',
            now()->addHours(24),
            ['booking' => $booking->id],
        );

        $response = $this->get($url);

        $response->assertOk();
        $response->assertViewHas('alreadyProcessed', true);
    }
}
