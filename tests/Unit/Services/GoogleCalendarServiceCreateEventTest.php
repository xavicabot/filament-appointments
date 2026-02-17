<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarApi;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\EntryPoint;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\Resource\Events as EventsResource;
use Mockery;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarService;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class GoogleCalendarServiceCreateEventTest extends TestCase
{
    private function createConnection(): CalendarConnection
    {
        return CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => \Illuminate\Support\Facades\Crypt::encryptString('fake-token'),
            'refresh_token' => \Illuminate\Support\Facades\Crypt::encryptString('fake-refresh'),
            'status' => 'connected',
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        ]);
    }

    private function createBooking(): AppointmentBooking
    {
        return AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);
    }

    public function test_returns_meet_link_from_video_entry_point(): void
    {
        $conn = $this->createConnection();
        $booking = $this->createBooking();

        $entryPoint = Mockery::mock(EntryPoint::class);
        $entryPoint->shouldReceive('getEntryPointType')->andReturn('video');
        $entryPoint->shouldReceive('getUri')->andReturn('https://meet.google.com/test-meet');

        $conferenceData = Mockery::mock(ConferenceData::class);
        $conferenceData->shouldReceive('getEntryPoints')->andReturn([$entryPoint]);

        $createdEvent = Mockery::mock(Event::class);
        $createdEvent->shouldReceive('getConferenceData')->andReturn($conferenceData);

        $eventsResource = Mockery::mock(EventsResource::class);
        $eventsResource->shouldReceive('insert')
            ->once()
            ->andReturn($createdEvent);

        $calendarApi = Mockery::mock(GoogleCalendarApi::class);
        $calendarApi->events = $eventsResource;

        $googleClient = Mockery::mock(GoogleClient::class);
        $googleClient->shouldReceive('setAccessToken');
        $googleClient->shouldReceive('isAccessTokenExpired')->andReturn(false);

        $service = Mockery::mock(GoogleCalendarService::class, [$googleClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('makeCalendarService')->andReturn($calendarApi);

        $meetLink = $service->createEventWithMeet($conn, $booking);

        $this->assertSame('https://meet.google.com/test-meet', $meetLink);
    }

    public function test_event_description_includes_metadata_excluding_meet_link(): void
    {
        $conn = $this->createConnection();

        $booking = AppointmentBooking::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
            'metadata' => [
                'phone' => '612345678',
                'source' => 'onboarding',
                'meet_link' => 'https://meet.google.com/should-be-excluded',
            ],
        ]);

        $capturedEvent = null;

        $conferenceData = Mockery::mock(ConferenceData::class);
        $conferenceData->shouldReceive('getEntryPoints')->andReturn([]);

        $createdEvent = Mockery::mock(Event::class);
        $createdEvent->shouldReceive('getConferenceData')->andReturn($conferenceData);

        $eventsResource = Mockery::mock(EventsResource::class);
        $eventsResource->shouldReceive('insert')
            ->once()
            ->withArgs(function ($calendarId, $event) use (&$capturedEvent) {
                $capturedEvent = $event;

                return true;
            })
            ->andReturn($createdEvent);

        $calendarApi = Mockery::mock(GoogleCalendarApi::class);
        $calendarApi->events = $eventsResource;

        $googleClient = Mockery::mock(GoogleClient::class);
        $googleClient->shouldReceive('setAccessToken');
        $googleClient->shouldReceive('isAccessTokenExpired')->andReturn(false);

        $service = Mockery::mock(GoogleCalendarService::class, [$googleClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('makeCalendarService')->andReturn($calendarApi);

        $service->createEventWithMeet($conn, $booking);

        $this->assertNotNull($capturedEvent);
        $description = $capturedEvent->getDescription();
        $this->assertStringContainsString('Phone: 612345678', $description);
        $this->assertStringContainsString('Source: onboarding', $description);
        $this->assertStringNotContainsString('meet_link', $description);
        $this->assertStringNotContainsString('should-be-excluded', $description);
    }

    public function test_returns_null_when_no_video_entry_point(): void
    {
        $conn = $this->createConnection();
        $booking = $this->createBooking();

        $entryPoint = Mockery::mock(EntryPoint::class);
        $entryPoint->shouldReceive('getEntryPointType')->andReturn('phone');
        $entryPoint->shouldReceive('getUri')->never();

        $conferenceData = Mockery::mock(ConferenceData::class);
        $conferenceData->shouldReceive('getEntryPoints')->andReturn([$entryPoint]);

        $createdEvent = Mockery::mock(Event::class);
        $createdEvent->shouldReceive('getConferenceData')->andReturn($conferenceData);

        $eventsResource = Mockery::mock(EventsResource::class);
        $eventsResource->shouldReceive('insert')
            ->once()
            ->andReturn($createdEvent);

        $calendarApi = Mockery::mock(GoogleCalendarApi::class);
        $calendarApi->events = $eventsResource;

        $googleClient = Mockery::mock(GoogleClient::class);
        $googleClient->shouldReceive('setAccessToken');
        $googleClient->shouldReceive('isAccessTokenExpired')->andReturn(false);

        $service = Mockery::mock(GoogleCalendarService::class, [$googleClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('makeCalendarService')->andReturn($calendarApi);

        $meetLink = $service->createEventWithMeet($conn, $booking);

        $this->assertNull($meetLink);
    }
}
