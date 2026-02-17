<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Services;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Mail;
use Mockery;
use XaviCabot\FilamentAppointments\Mail\AppointmentConfirmationMail;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Services\AppointmentService;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;
use XaviCabot\FilamentAppointments\Support\HasBookings;
use XaviCabot\FilamentAppointments\Support\SlotOwner;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class FakeClient extends Authenticatable
{
    use HasBookings;

    protected $table = 'users';

    protected $fillable = ['id', 'name', 'email'];

    public $timestamps = false;
}

class AppointmentServiceTest extends TestCase
{
    protected AppointmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });

        Mail::fake();

        $mock = Mockery::mock(GoogleCalendarServiceInterface::class);
        $this->app->instance(GoogleCalendarServiceInterface::class, $mock);

        $this->service = app(AppointmentService::class);
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

    private function createClient(): FakeClient
    {
        return FakeClient::create([
            'id' => 10,
            'name' => 'Test Client',
            'email' => 'client@example.com',
        ]);
    }

    public function test_creates_confirmed_booking_by_default(): void
    {
        $this->createRule();
        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('09:00', $booking->start_time);
        $this->assertSame('09:30', $booking->end_time);
    }

    public function test_creates_pending_booking_when_confirmation_required(): void
    {
        config()->set('filament-appointments.bookings.require_confirmation', true);

        $this->createRule();
        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('pending', $booking->status);
    }

    public function test_sends_email_to_client(): void
    {
        $this->createRule();
        $client = $this->createClient();

        $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        Mail::assertSent(AppointmentConfirmationMail::class, function ($mail) {
            return $mail->hasTo('client@example.com');
        });
    }

    public function test_throws_exception_when_slot_is_taken(): void
    {
        $this->createRule();
        $client = $this->createClient();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => 'confirmed',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);
    }

    public function test_throws_exception_when_pending_slot_exists(): void
    {
        $this->createRule();
        $client = $this->createClient();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => 'pending',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);
    }

    public function test_calculates_end_time_from_rule_interval(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 60,
            'is_active' => true,
        ]);

        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('10:00', $booking->end_time);
    }

    public function test_defaults_to_30_minute_interval_without_rule(): void
    {
        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('09:30', $booking->end_time);
    }

    public function test_generates_meet_link_when_connection_exists(): void
    {
        $this->createRule();
        $client = $this->createClient();

        CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'status' => 'connected',
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
            ],
        ]);

        $mock = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mock->shouldReceive('createEventWithMeet')
            ->once()
            ->andReturn('https://meet.google.com/abc-defg-hij');
        $this->app->instance(GoogleCalendarServiceInterface::class, $mock);

        $service = app(AppointmentService::class);
        $booking = $service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('https://meet.google.com/abc-defg-hij', $booking->metadata['meet_link']);
    }

    public function test_no_meet_link_without_events_scope(): void
    {
        $this->createRule();
        $client = $this->createClient();

        CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'status' => 'connected',
            'scopes' => ['https://www.googleapis.com/auth/calendar.readonly'],
        ]);

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertNull($booking->metadata);
    }

    public function test_booking_without_metadata_backwards_compatible(): void
    {
        $this->createRule();
        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertNull($booking->metadata);
    }

    public function test_booking_with_metadata_persists_in_json(): void
    {
        $this->createRule();
        $client = $this->createClient();

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client, [
            'phone' => '612345678',
            'source' => 'onboarding',
        ]);

        $booking->refresh();
        $this->assertSame('612345678', $booking->metadata['phone']);
        $this->assertSame('onboarding', $booking->metadata['source']);
    }

    public function test_meet_link_not_overwritten_by_consumer_metadata(): void
    {
        $this->createRule();
        $client = $this->createClient();

        CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'status' => 'connected',
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
            ],
        ]);

        $mock = Mockery::mock(GoogleCalendarServiceInterface::class);
        $mock->shouldReceive('createEventWithMeet')
            ->once()
            ->andReturn('https://meet.google.com/real-link');
        $this->app->instance(GoogleCalendarServiceInterface::class, $mock);

        $service = app(AppointmentService::class);
        $booking = $service->createBooking($this->owner(), '2026-02-16', '09:00', $client, [
            'phone' => '612345678',
            'meet_link' => 'https://fake.com/should-be-overwritten',
        ]);

        $this->assertSame('https://meet.google.com/real-link', $booking->metadata['meet_link']);
        $this->assertSame('612345678', $booking->metadata['phone']);
    }

    public function test_cancelled_slot_allows_new_booking(): void
    {
        $this->createRule();
        $client = $this->createClient();

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'status' => 'cancelled',
        ]);

        $booking = $this->service->createBooking($this->owner(), '2026-02-16', '09:00', $client);

        $this->assertSame('confirmed', $booking->status);
    }
}
