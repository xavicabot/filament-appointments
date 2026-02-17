<?php

namespace XaviCabot\FilamentAppointments\Support;

use Carbon\CarbonImmutable;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Models\CalendarBusyCache;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;
use XaviCabot\FilamentAppointments\Services\GoogleCalendarServiceInterface;

class SlotResolver
{
    /**
     * @return array<int, array{value:string,label:string,disabled:bool,reason:?string}>
     */
    public function forDate(string $date, SlotOwner $owner): array
    {
        $tz = (string) (config('filament-appointments.timezone') ?: 'Europe/Madrid');

        $day = CarbonImmutable::parse($date, $tz);
        $weekday = (int) $day->isoWeekday(); // 1..7

        $rules = AppointmentRule::query()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        // Sync Google blocks if needed (persists busy windows as fa_blocks)
        $this->syncGoogleBlocksIfNeeded($day, $owner, $tz);

        // Query all blocks (manual + google) for this date
        $blocks = AppointmentBlock::query()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->whereDate('date', $day->toDateString())
            ->get();

        $bookedRanges = $this->bookedSlots($day, $owner, $tz);

        $now = CarbonImmutable::now($tz);
        $isToday = $day->isSameDay($now);

        $slots = [];

        foreach ($rules as $rule) {
            $interval = max(5, (int) $rule->interval_minutes);

            $start = CarbonImmutable::parse($day->format('Y-m-d').' '.$rule->start_time, $tz);
            $end = CarbonImmutable::parse($day->format('Y-m-d').' '.$rule->end_time, $tz);

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            for ($t = $start; $t->addMinutes($interval)->lessThanOrEqualTo($end); $t = $t->addMinutes($interval)) {
                $value = $t->format('H:i');

                $slotStart = $t;
                $slotEnd = $t->addMinutes($interval);

                $disabled = false;
                $reason = null;

                if ($isToday && $slotStart->lte($now)) {
                    $disabled = true;
                    $reason = 'past';
                } else {
                    $blockedReason = $this->isBlocked($day, $slotStart, $slotEnd, $blocks, $tz);
                    if ($blockedReason !== false) {
                        $disabled = true;
                        $reason = $blockedReason;
                    } else {
                        $bookedReason = $this->isBooked($slotStart, $slotEnd, $bookedRanges, $tz);
                        if ($bookedReason !== false) {
                            $disabled = true;
                            $reason = $bookedReason;
                        }
                    }
                }

                $slots[] = [
                    'value' => $value,
                    'label' => $value,
                    'disabled' => $disabled,
                    'reason' => $reason,
                ];
            }
        }

        // De-duplicate by value (if multiple rules overlap, first wins)
        $unique = [];
        foreach ($slots as $slot) {
            $unique[$slot['value']] ??= $slot;
        }

        ksort($unique);

        return array_values($unique);
    }

    /**
     * Returns 'blocked', 'google_busy', or false.
     */
    private function isBlocked(CarbonImmutable $day, CarbonImmutable $slotStart, CarbonImmutable $slotEnd, $blocks, string $tz): string|false
    {
        foreach ($blocks as $block) {
            if ($block->start_time === null && $block->end_time === null) {
                return ($block->source ?? 'manual') === 'google' ? 'google_busy' : 'blocked';
            }

            $bStart = CarbonImmutable::parse($day->format('Y-m-d').' '.($block->start_time ?? '00:00'), $tz);
            $bEnd = CarbonImmutable::parse($day->format('Y-m-d').' '.($block->end_time ?? '23:59'), $tz);

            if ($slotStart->lt($bEnd) && $slotEnd->gt($bStart)) {
                return ($block->source ?? 'manual') === 'google' ? 'google_busy' : 'blocked';
            }
        }

        return false;
    }

    /**
     * Sync Google Calendar busy windows into fa_blocks if the cache has expired.
     */
    private function syncGoogleBlocksIfNeeded(CarbonImmutable $day, SlotOwner $owner, string $tz): void
    {
        if ($owner->type !== 'user') {
            return;
        }

        $conn = CalendarConnection::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->first();

        if (! $conn) {
            return;
        }

        $included = CalendarSource::query()
            ->where('connection_id', $conn->id)
            ->where('included', true)
            ->pluck('external_calendar_id')
            ->values()
            ->all();

        if (empty($included)) {
            return;
        }

        $hash = sha1(json_encode($included));
        $ttl = (int) (config('filament-appointments.google.cache_ttl_seconds') ?? 120);

        $cached = CalendarBusyCache::query()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->whereDate('date', $day->toDateString())
            ->where('calendars_hash', $hash)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($cached) {
            return;
        }

        $busy = [];

        try {
            $service = app(GoogleCalendarServiceInterface::class);

            $dayStart = $day->startOfDay()->toIso8601String();
            $dayEnd = $day->endOfDay()->toIso8601String();

            $busy = $service->fetchBusyWindows($conn, $included, $dayStart, $dayEnd);

            // Delete existing google blocks for this owner + date
            AppointmentBlock::query()
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id)
                ->where('source', 'google')
                ->whereDate('date', $day->toDateString())
                ->delete();

            // Create new blocks from busy windows
            foreach ($busy as $window) {
                $wStart = CarbonImmutable::parse($window['start'])->setTimezone($tz);
                $wEnd = CarbonImmutable::parse($window['end'])->setTimezone($tz);

                AppointmentBlock::create([
                    'owner_type' => $owner->type,
                    'owner_id' => $owner->id,
                    'date' => $day->toDateString(),
                    'start_time' => $wStart->format('H:i'),
                    'end_time' => $wEnd->format('H:i'),
                    'reason' => 'Google Calendar',
                    'source' => 'google',
                ]);
            }
        } catch (\Throwable $e) {
            // Graceful degradation: if Google API fails, keep existing blocks
        }

        CalendarBusyCache::query()->updateOrCreate(
            [
                'owner_type' => $owner->type,
                'owner_id' => $owner->id,
                'calendars_hash' => $hash,
            ],
            [
                'date' => $day->toDateString(),
                'payload' => $busy,
                'expires_at' => now()->addSeconds($ttl),
            ],
        );
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    private function bookedSlots(CarbonImmutable $day, SlotOwner $owner, string $tz)
    {
        return AppointmentBooking::active()
            ->forOwner($owner)
            ->forDate($day->toDateString())
            ->get(['start_time', 'end_time', 'status']);
    }

    /**
     * @return string|false
     */
    private function isBooked(CarbonImmutable $slotStart, CarbonImmutable $slotEnd, $bookings, string $tz): bool|string
    {
        foreach ($bookings as $booking) {
            $bStart = CarbonImmutable::parse($slotStart->format('Y-m-d').' '.$booking->start_time, $tz);
            $bEnd = CarbonImmutable::parse($slotStart->format('Y-m-d').' '.$booking->end_time, $tz);

            if ($slotStart->lt($bEnd) && $slotEnd->gt($bStart)) {
                return $booking->status === 'pending' ? 'pending' : 'booked';
            }
        }

        return false;
    }
}
