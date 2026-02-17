<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Support;

use Illuminate\Support\Facades\Crypt;
use Mockery;
use XaviCabot\FilamentAppointments\Models\CalendarBusyCache;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;
use XaviCabot\FilamentAppointments\Support\SlotOwner;
use XaviCabot\FilamentAppointments\Support\SlotResolver;
use XaviCabot\FilamentAppointments\Tests\TestCase;

class SlotResolverTest extends TestCase
{
    private SlotResolver $resolver;

    private $mockGoogleService;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to early morning so test slots (09:00+) are always in the future
        $this->travelTo(now()->setTime(6, 0, 0));

        $this->mockGoogleService = Mockery::mock(GoogleCalendarServiceInterface::class);
        $this->app->instance(GoogleCalendarServiceInterface::class, $this->mockGoogleService);

        $this->resolver = new SlotResolver;
    }

    private function owner(): SlotOwner
    {
        return new SlotOwner('user', 1);
    }

    public function test_no_rules_returns_empty(): void
    {
        $result = $this->resolver->forDate('2026-02-16', $this->owner()); // Monday

        $this->assertSame([], $result);
    }

    public function test_rule_generates_correct_slots(): void
    {
        // 2026-02-16 is a Monday (weekday=1)
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $this->assertCount(6, $result);
        $this->assertSame('09:00', $result[0]['value']);
        $this->assertSame('09:30', $result[1]['value']);
        $this->assertSame('10:00', $result[2]['value']);
        $this->assertSame('10:30', $result[3]['value']);
        $this->assertSame('11:00', $result[4]['value']);
        $this->assertSame('11:30', $result[5]['value']);

        foreach ($result as $slot) {
            $this->assertFalse($slot['disabled']);
            $this->assertNull($slot['reason']);
        }
    }

    public function test_block_disables_overlapping_slots(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        // 10:00 and 10:30 should be blocked
        $blocked = collect($result)->where('disabled', true)->pluck('value')->all();
        $this->assertContains('10:00', $blocked);
        $this->assertContains('10:30', $blocked);

        foreach (collect($result)->where('disabled', true) as $slot) {
            $this->assertSame('blocked', $slot['reason']);
        }

        // 09:00, 09:30, 11:00, 11:30 should be enabled
        $enabled = collect($result)->where('disabled', false)->pluck('value')->all();
        $this->assertContains('09:00', $enabled);
        $this->assertContains('11:00', $enabled);
    }

    public function test_allday_block_disables_everything(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => null,
            'end_time' => null,
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        foreach ($result as $slot) {
            $this->assertTrue($slot['disabled']);
            $this->assertSame('blocked', $slot['reason']);
        }
    }

    public function test_google_busy_disables_slots(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'My Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $this->mockGoogleService
            ->shouldReceive('fetchBusyWindows')
            ->once()
            ->andReturn([
                ['start' => '2026-02-16T10:00:00+01:00', 'end' => '2026-02-16T11:00:00+01:00'],
            ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $googleBusy = collect($result)->where('reason', 'google_busy')->pluck('value')->all();
        $this->assertContains('10:00', $googleBusy);
        $this->assertContains('10:30', $googleBusy);
    }

    public function test_overlapping_rules_dedup_first_wins(): void
    {
        // Two rules covering overlapping times
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        // Should have unique values only: 09:00, 09:30, 10:00, 10:30, 11:00, 11:30
        $values = collect($result)->pluck('value')->all();
        $this->assertSame($values, array_unique($values));
        $this->assertCount(6, $result);
    }

    public function test_non_user_owner_no_google_lookup(): void
    {
        $teamOwner = new SlotOwner('team', 1);

        AppointmentRule::create([
            'owner_type' => 'team',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        // Google service should never be called for non-user owners
        $this->mockGoogleService->shouldNotReceive('fetchBusyWindows');

        $result = $this->resolver->forDate('2026-02-16', $teamOwner);

        $this->assertCount(2, $result);
    }

    public function test_no_calendar_connection_all_enabled(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        // No CalendarConnection exists - service should not be called
        $this->mockGoogleService->shouldNotReceive('fetchBusyWindows');

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $this->assertCount(2, $result);
        foreach ($result as $slot) {
            $this->assertFalse($slot['disabled']);
        }
    }

    public function test_valid_cache_does_not_call_service(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        // Google block persisted from a previous sync
        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:00',
            'end_time' => '09:30',
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        $hash = sha1(json_encode(['primary']));

        CalendarBusyCache::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'calendars_hash' => $hash,
            'payload' => [
                ['start' => '2026-02-16T09:00:00+01:00', 'end' => '2026-02-16T09:30:00+01:00'],
            ],
            'expires_at' => now()->addMinutes(5),
        ]);

        // Service should NOT be called because cache is valid
        $this->mockGoogleService->shouldNotReceive('fetchBusyWindows');

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        $busySlots = collect($result)->where('reason', 'google_busy')->pluck('value')->all();
        $this->assertContains('09:00', $busySlots);
    }

    public function test_resolver_uses_blocks_as_sole_source_no_freeBusy_call(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        // Google block already persisted in fa_blocks
        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '09:30',
            'end_time' => '10:00',
            'source' => 'google',
            'reason' => 'Google Calendar',
        ]);

        // Manual block
        AppointmentBlock::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'start_time' => '10:00',
            'end_time' => '10:30',
            'source' => 'manual',
            'reason' => 'Day off',
        ]);

        $hash = sha1(json_encode(['primary']));

        CalendarBusyCache::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'calendars_hash' => $hash,
            'payload' => [
                ['start' => '2026-02-16T09:30:00+01:00', 'end' => '2026-02-16T10:00:00+01:00'],
            ],
            'expires_at' => now()->addMinutes(5),
        ]);

        // fetchBusyWindows should NEVER be called — blocks are the sole source
        $this->mockGoogleService->shouldNotReceive('fetchBusyWindows');

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        // 09:30 → google_busy (from google block)
        $googleSlot = collect($result)->firstWhere('value', '09:30');
        $this->assertTrue($googleSlot['disabled']);
        $this->assertSame('google_busy', $googleSlot['reason']);

        // 10:00 → blocked (from manual block)
        $manualSlot = collect($result)->firstWhere('value', '10:00');
        $this->assertTrue($manualSlot['disabled']);
        $this->assertSame('blocked', $manualSlot['reason']);

        // 09:00 → enabled
        $freeSlot = collect($result)->firstWhere('value', '09:00');
        $this->assertFalse($freeSlot['disabled']);
        $this->assertNull($freeSlot['reason']);
    }

    public function test_expired_cache_calls_service_and_updates_cache(): void
    {
        AppointmentRule::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'interval_minutes' => 30,
            'is_active' => true,
        ]);

        $conn = CalendarConnection::create([
            'user_id' => 1,
            'provider' => 'google',
            'access_token' => Crypt::encryptString('token'),
            'refresh_token' => Crypt::encryptString('refresh'),
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);

        CalendarSource::create([
            'connection_id' => $conn->id,
            'external_calendar_id' => 'primary',
            'name' => 'Calendar',
            'included' => true,
            'primary' => true,
        ]);

        $hash = sha1(json_encode(['primary']));

        // Expired cache
        CalendarBusyCache::create([
            'owner_type' => 'user',
            'owner_id' => 1,
            'date' => '2026-02-16',
            'calendars_hash' => $hash,
            'payload' => [],
            'expires_at' => now()->subMinute(),
        ]);

        $this->mockGoogleService
            ->shouldReceive('fetchBusyWindows')
            ->once()
            ->andReturn([
                ['start' => '2026-02-16T09:00:00+01:00', 'end' => '2026-02-16T09:30:00+01:00'],
            ]);

        $result = $this->resolver->forDate('2026-02-16', $this->owner());

        // Cache should be updated
        $cache = CalendarBusyCache::where('owner_type', 'user')
            ->where('owner_id', 1)
            ->whereDate('date', '2026-02-16')
            ->first();

        $this->assertNotEmpty($cache->payload);
        $this->assertTrue($cache->expires_at->isFuture());
    }
}
