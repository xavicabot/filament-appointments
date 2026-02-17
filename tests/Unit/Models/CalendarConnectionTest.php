<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Models;

use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class CalendarConnectionTest extends TestCase
{
    public function test_scopes_cast_as_array(): void
    {
        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'scopes' => ['https://www.googleapis.com/auth/calendar.readonly'],
            'status' => 'connected',
        ]);

        $conn->refresh();

        $this->assertIsArray($conn->scopes);
        $this->assertSame(['https://www.googleapis.com/auth/calendar.readonly'], $conn->scopes);
    }

    public function test_expires_at_cast_as_datetime(): void
    {
        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'expires_at' => '2026-01-01 12:00:00',
            'status' => 'connected',
        ]);

        $conn->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $conn->expires_at);
    }

    public function test_calendar_sources_relationship(): void
    {
        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'My Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $this->assertCount(1, $conn->calendarSources);
        $this->assertSame('My Calendar', $conn->calendarSources->first()->name);
    }
}
