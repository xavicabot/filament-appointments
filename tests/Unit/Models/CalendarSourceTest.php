<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Models;

use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class CalendarSourceTest extends TestCase
{
    public function test_included_cast_as_bool(): void
    {
        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'status' => 'connected',
        ]);

        $source = CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'My Calendar',
            'included' => 1,
            'primary' => false,
        ]);

        $source->refresh();

        $this->assertIsBool($source->included);
        $this->assertTrue($source->included);
    }

    public function test_primary_cast_as_bool(): void
    {
        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'status' => 'connected',
        ]);

        $source = CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'My Calendar',
            'included' => true,
            'primary' => 1,
        ]);

        $source->refresh();

        $this->assertIsBool($source->primary);
        $this->assertTrue($source->primary);
    }
}
