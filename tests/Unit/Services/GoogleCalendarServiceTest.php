<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarApi;
use Google\Service\Calendar\CalendarList as CalendarListResource;
use Google\Service\Calendar\CalendarListEntry;
use Google\Service\Calendar\FreeBusyCalendar;
use Google\Service\Calendar\FreeBusyResponse;
use Google\Service\Calendar\Resource\Calendarlist as CalendarListService;
use Google\Service\Calendar\Resource\Freebusy as FreebusyService;
use Google\Service\Calendar\TimePeriod;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarService;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    private GoogleCalendarService $service;

    private GoogleClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(GoogleClient::class);
        $this->service = new GoogleCalendarService($this->mockClient);
    }

    private function createConnection(array $overrides = []): CalendarConnection
    {
        return CalendarConnection::create(array_merge([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => Crypt::encryptString('test-access-token'),
            'refresh_token' => Crypt::encryptString('test-refresh-token'),
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.readonly'],
            'status' => 'connected',
        ], $overrides));
    }

    // --- Token management ---

    public function test_valid_token_returns_client_without_refresh(): void
    {
        $conn = $this->createConnection(['expires_at' => now()->addHour()]);

        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(false);

        $client = $this->service->getAuthenticatedClient($conn);

        $this->assertSame($this->mockClient, $client);
    }

    public function test_expired_token_refreshes_and_persists(): void
    {
        $conn = $this->createConnection(['expires_at' => now()->subMinute()]);

        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(true);
        $this->mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
            ]);

        $client = $this->service->getAuthenticatedClient($conn);

        $this->assertSame($this->mockClient, $client);

        $conn->refresh();
        $this->assertSame('new-access-token', Crypt::decryptString($conn->access_token));
        $this->assertSame('connected', $conn->status);
    }

    public function test_token_in_60s_buffer_refreshes(): void
    {
        $conn = $this->createConnection(['expires_at' => now()->addSeconds(30)]);

        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(true);
        $this->mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ]);

        $this->service->getAuthenticatedClient($conn);

        $conn->refresh();
        $this->assertSame('refreshed-token', Crypt::decryptString($conn->access_token));
    }

    public function test_refresh_failure_marks_disconnected_and_throws(): void
    {
        $conn = $this->createConnection(['expires_at' => now()->subMinute()]);

        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(true);
        $this->mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn(['error' => 'invalid_grant']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google token refresh failed');

        try {
            $this->service->getAuthenticatedClient($conn);
        } finally {
            $conn->refresh();
            $this->assertSame('disconnected', $conn->status);
        }
    }

    public function test_refresh_returns_new_refresh_token_persists_it(): void
    {
        $conn = $this->createConnection(['expires_at' => now()->subMinute()]);

        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(true);
        $this->mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]);

        $this->service->getAuthenticatedClient($conn);

        $conn->refresh();
        $this->assertSame('new-refresh', Crypt::decryptString($conn->refresh_token));
    }

    // --- syncCalendars ---

    public function test_syncCalendars_creates_calendar_source_records(): void
    {
        $conn = $this->createConnection();

        $entry = $this->makeCalendarListEntry('cal-1', 'Work Calendar', false);

        $this->mockCalendarList($conn, [$entry]);

        $sources = $this->service->syncCalendars($conn);

        $this->assertCount(1, $sources);
        $this->assertSame('cal-1', $sources[0]->external_calendar_id);
        $this->assertSame('Work Calendar', $sources[0]->name);
    }

    public function test_syncCalendars_updates_name_but_not_included(): void
    {
        $conn = $this->createConnection();

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'cal-1',
            'name' => 'Old Name',
            'included' => false,
            'primary' => false,
        ]);

        $entry = $this->makeCalendarListEntry('cal-1', 'New Name', false);

        $this->mockCalendarList($conn, [$entry]);

        $sources = $this->service->syncCalendars($conn);

        $this->assertCount(1, $sources);
        $this->assertSame('New Name', $sources[0]->name);
        $this->assertFalse($sources[0]->included); // user's choice preserved
    }

    public function test_syncCalendars_deletes_removed_calendars(): void
    {
        $conn = $this->createConnection();

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'removed-cal',
            'name' => 'Removed',
            'included' => true,
            'primary' => false,
        ]);

        $this->mockCalendarList($conn, []);

        $sources = $this->service->syncCalendars($conn);

        $this->assertCount(0, $sources);
        $this->assertDatabaseMissing('fa_sources', ['external_calendar_id' => 'removed-cal']);
    }

    public function test_syncCalendars_marks_primary_calendar(): void
    {
        $conn = $this->createConnection();

        $primary = $this->makeCalendarListEntry('primary-cal', 'Primary', true);
        $secondary = $this->makeCalendarListEntry('secondary-cal', 'Secondary', false);

        $this->mockCalendarList($conn, [$primary, $secondary]);

        $sources = $this->service->syncCalendars($conn);

        $this->assertCount(2, $sources);

        $primarySource = collect($sources)->firstWhere('external_calendar_id', 'primary-cal');
        $secondarySource = collect($sources)->firstWhere('external_calendar_id', 'secondary-cal');

        $this->assertTrue($primarySource->primary);
        $this->assertFalse($secondarySource->primary);
    }

    // --- fetchBusyWindows ---

    public function test_fetchBusyWindows_returns_busy_periods(): void
    {
        $conn = $this->createConnection();

        $this->mockFreeBusy($conn, [
            'cal-1' => [
                ['start' => '2026-02-16T10:00:00Z', 'end' => '2026-02-16T11:00:00Z'],
            ],
        ]);

        $busy = $this->service->fetchBusyWindows(
            $conn,
            ['cal-1'],
            '2026-02-16T00:00:00Z',
            '2026-02-16T23:59:59Z',
        );

        $this->assertCount(1, $busy);
        $this->assertSame('2026-02-16T10:00:00Z', $busy[0]['start']);
        $this->assertSame('2026-02-16T11:00:00Z', $busy[0]['end']);
    }

    public function test_fetchBusyWindows_no_busy_returns_empty(): void
    {
        $conn = $this->createConnection();

        $this->mockFreeBusy($conn, ['cal-1' => []]);

        $busy = $this->service->fetchBusyWindows(
            $conn,
            ['cal-1'],
            '2026-02-16T00:00:00Z',
            '2026-02-16T23:59:59Z',
        );

        $this->assertCount(0, $busy);
    }

    public function test_fetchBusyWindows_merges_multiple_calendars(): void
    {
        $conn = $this->createConnection();

        $this->mockFreeBusy($conn, [
            'cal-1' => [
                ['start' => '2026-02-16T10:00:00Z', 'end' => '2026-02-16T11:00:00Z'],
            ],
            'cal-2' => [
                ['start' => '2026-02-16T14:00:00Z', 'end' => '2026-02-16T15:00:00Z'],
            ],
        ]);

        $busy = $this->service->fetchBusyWindows(
            $conn,
            ['cal-1', 'cal-2'],
            '2026-02-16T00:00:00Z',
            '2026-02-16T23:59:59Z',
        );

        $this->assertCount(2, $busy);
    }

    // --- syncBusyBlocks ---

    public function test_syncBusyBlocks_creates_google_blocks(): void
    {
        $conn = $this->createConnection();

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $this->mockFreeBusy($conn, [
            'primary' => [
                ['start' => '2026-02-16T10:00:00Z', 'end' => '2026-02-16T11:00:00Z'],
                ['start' => '2026-02-17T14:00:00Z', 'end' => '2026-02-17T15:00:00Z'],
            ],
        ]);

        $count = $this->service->syncBusyBlocks($conn, 'user', 1, 30);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('fa_blocks', [
            'owner_type' => 'user',
            'owner_id' => 1,
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        $blocks = AppointmentBlock::where('source', 'google')->get();
        $this->assertCount(2, $blocks);
    }

    public function test_syncBusyBlocks_removes_cancelled_google_events(): void
    {
        $conn = $this->createConnection();

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        // Pre-existing google block (from a previous sync)
        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        // Manual block should NOT be deleted
        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:00',
            'source' => 'manual',
        ]);

        // New sync returns empty (event was cancelled in Google)
        $this->mockFreeBusy($conn, ['primary' => []]);

        $count = $this->service->syncBusyBlocks($conn, 'user', 1, 30);

        $this->assertSame(0, $count);

        // Google block should be deleted
        $this->assertDatabaseMissing('fa_blocks', ['source' => 'google']);

        // Manual block should remain
        $this->assertDatabaseHas('fa_blocks', ['source' => 'manual', 'start_time' => '14:00']);
    }

    // --- Helpers ---

    private function makeCalendarListEntry(string $id, string $summary, bool $primary): CalendarListEntry
    {
        $entry = Mockery::mock(CalendarListEntry::class);
        $entry->shouldReceive('getId')->andReturn($id);
        $entry->shouldReceive('getSummary')->andReturn($summary);
        $entry->shouldReceive('getPrimary')->andReturn($primary);

        return $entry;
    }

    private function mockCalendarList(CalendarConnection $conn, array $entries): void
    {
        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(false);

        $calendarList = Mockery::mock(CalendarListResource::class);
        $calendarList->shouldReceive('getItems')->andReturn($entries);

        $calendarListService = Mockery::mock(CalendarListService::class);
        $calendarListService->shouldReceive('listCalendarList')->once()->andReturn($calendarList);

        $calendarApi = Mockery::mock(GoogleCalendarApi::class);
        $calendarApi->calendarList = $calendarListService;

        $this->service = Mockery::mock(GoogleCalendarService::class, [$this->mockClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $this->service->shouldReceive('makeCalendarService')
            ->once()
            ->andReturn($calendarApi);
    }

    private function mockFreeBusy(CalendarConnection $conn, array $calendarsWithBusy): void
    {
        $this->mockClient->shouldReceive('setAccessToken')->once();
        $this->mockClient->shouldReceive('isAccessTokenExpired')->once()->andReturn(false);

        $calendarsMap = [];
        foreach ($calendarsWithBusy as $calId => $busyPeriods) {
            $timePeriods = array_map(function ($period) {
                $tp = Mockery::mock(TimePeriod::class);
                $tp->shouldReceive('getStart')->andReturn($period['start']);
                $tp->shouldReceive('getEnd')->andReturn($period['end']);

                return $tp;
            }, $busyPeriods);

            $freeBusyCal = Mockery::mock(FreeBusyCalendar::class);
            $freeBusyCal->shouldReceive('getBusy')->andReturn($timePeriods);
            $calendarsMap[$calId] = $freeBusyCal;
        }

        $freeBusyResponse = Mockery::mock(FreeBusyResponse::class);
        $freeBusyResponse->shouldReceive('getCalendars')->andReturn($calendarsMap);

        $freebusyService = Mockery::mock(FreebusyService::class);
        $freebusyService->shouldReceive('query')->once()->andReturn($freeBusyResponse);

        $calendarApi = Mockery::mock(GoogleCalendarApi::class);
        $calendarApi->freebusy = $freebusyService;

        $this->service = Mockery::mock(GoogleCalendarService::class, [$this->mockClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $this->service->shouldReceive('makeCalendarService')
            ->once()
            ->andReturn($calendarApi);
    }
}
