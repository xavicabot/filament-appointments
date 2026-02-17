<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Support\HasBookings;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class BookingClient extends Authenticatable
{
    use HasBookings;

    protected $table = 'users';

    protected $fillable = ['id', 'name', 'email'];

    public $timestamps = false;
}

class HasBookingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });
    }

    public function test_get_email_for_booking_returns_email(): void
    {
        $client = BookingClient::create(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

        $this->assertSame('john@example.com', $client->getEmailForBooking());
    }

    public function test_get_name_for_booking_returns_name(): void
    {
        $client = BookingClient::create(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

        $this->assertSame('John', $client->getNameForBooking());
    }

    public function test_bookings_morphmany_relationship(): void
    {
        $client = BookingClient::create(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

        AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'client_type' => BookingClient::class,
            'client_id' => 1,
            'status' => 'confirmed',
        ]);

        $this->assertCount(1, $client->bookings);
        $this->assertSame('10:00', $client->bookings->first()->start_time);
    }
}
