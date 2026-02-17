<?php

namespace XaviCabot\FilamentAppointments\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarApi;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use XaviCabot\FilamentAppointments\Models\AppointmentBlock;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\CalendarSource;

class GoogleCalendarService implements GoogleCalendarServiceInterface
{
    public function __construct(
        protected GoogleClient $client,
    ) {}

    public function getAuthenticatedClient(CalendarConnection $connection): GoogleClient
    {
        $this->client->setAccessToken(Crypt::decryptString($connection->access_token));

        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = Crypt::decryptString($connection->refresh_token);
            $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($token['error'])) {
                $connection->update(['status' => 'disconnected']);

                throw new \RuntimeException('Google token refresh failed: ' . ($token['error'] ?? 'unknown'));
            }

            $updateData = [
                'access_token' => Crypt::encryptString($token['access_token']),
                'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            ];

            if (! empty($token['refresh_token'])) {
                $updateData['refresh_token'] = Crypt::encryptString($token['refresh_token']);
            }

            $connection->update($updateData);
        }

        return $this->client;
    }

    public function syncCalendars(CalendarConnection $connection): array
    {
        $client = $this->getAuthenticatedClient($connection);
        $calendarApi = $this->makeCalendarService($client);

        $calendarList = $calendarApi->calendarList->listCalendarList();
        $entries = $calendarList->getItems();

        $syncedIds = [];

        foreach ($entries as $entry) {
            $externalId = $entry->getId();
            $syncedIds[] = $externalId;

            $source = CalendarSource::firstOrNew([
                'connection_id' => $connection->id,
                'external_calendar_id' => $externalId,
            ]);

            $source->name = $entry->getSummary();
            $source->primary = (bool) $entry->getPrimary();

            if (! $source->exists) {
                $source->included = false;
            }

            $source->save();
        }

        // Delete calendars that no longer exist in Google
        CalendarSource::where('connection_id', $connection->id)
            ->whereNotIn('external_calendar_id', $syncedIds)
            ->delete();

        return CalendarSource::where('connection_id', $connection->id)->get()->all();
    }

    public function fetchBusyWindows(
        CalendarConnection $connection,
        array $calendarIds,
        string $timeMin,
        string $timeMax,
    ): array {
        $client = $this->getAuthenticatedClient($connection);
        $calendarApi = $this->makeCalendarService($client);

        $request = new FreeBusyRequest;
        $request->setTimeMin($timeMin);
        $request->setTimeMax($timeMax);
        $request->setItems(array_map(function (string $calId) {
            $item = new FreeBusyRequestItem;
            $item->setId($calId);

            return $item;
        }, $calendarIds));

        $response = $calendarApi->freebusy->query($request);

        $busy = [];
        $calendars = $response->getCalendars();

        foreach ($calendars as $calendar) {
            foreach ($calendar->getBusy() as $period) {
                $busy[] = [
                    'start' => $period->getStart(),
                    'end' => $period->getEnd(),
                ];
            }
        }

        return $busy;
    }

    public function syncBusyBlocks(
        CalendarConnection $connection,
        string $ownerType,
        int|string $ownerId,
        int $days = 30,
    ): int {
        $tz = (string) (config('filament-appointments.timezone') ?: 'Europe/Madrid');

        $included = CalendarSource::query()
            ->where('connection_id', $connection->id)
            ->where('included', true)
            ->pluck('external_calendar_id')
            ->values()
            ->all();

        if (empty($included)) {
            return 0;
        }

        $start = CarbonImmutable::today($tz);
        $end = $start->addDays($days);

        $busy = $this->fetchBusyWindows(
            $connection,
            $included,
            $start->startOfDay()->toIso8601String(),
            $end->endOfDay()->toIso8601String(),
        );

        // Remove existing google blocks in the sync range
        AppointmentBlock::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('source', 'google')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->delete();

        $count = 0;

        foreach ($busy as $window) {
            $wStart = CarbonImmutable::parse($window['start'])->setTimezone($tz);
            $wEnd = CarbonImmutable::parse($window['end'])->setTimezone($tz);

            // Cap at end of day if event crosses midnight
            if (! $wStart->isSameDay($wEnd)) {
                $wEnd = $wStart->setTime(23, 59);
            }

            AppointmentBlock::create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'date' => $wStart->toDateString(),
                'start_time' => $wStart->format('H:i'),
                'end_time' => $wEnd->format('H:i'),
                'reason' => 'Google Calendar',
                'source' => 'google',
            ]);

            $count++;
        }

        return $count;
    }

    public function createEventWithMeet(CalendarConnection $connection, AppointmentBooking $booking): ?string
    {
        $client = $this->getAuthenticatedClient($connection);
        $calendarApi = $this->makeCalendarService($client);

        $tz = (string) (config('filament-appointments.timezone') ?: 'Europe/Madrid');
        $date = $booking->date->format('Y-m-d');

        $startDateTime = new EventDateTime;
        $startDateTime->setDateTime("{$date}T{$booking->start_time}:00");
        $startDateTime->setTimeZone($tz);

        $endDateTime = new EventDateTime;
        $endDateTime->setDateTime("{$date}T{$booking->end_time}:00");
        $endDateTime->setTimeZone($tz);

        $conferenceKey = new ConferenceSolutionKey;
        $conferenceKey->setType('hangoutsMeet');

        $createRequest = new CreateConferenceRequest;
        $createRequest->setRequestId(Str::uuid()->toString());
        $createRequest->setConferenceSolutionKey($conferenceKey);

        $conferenceData = new ConferenceData;
        $conferenceData->setCreateRequest($createRequest);

        $event = new Event;
        $event->setSummary(__('filament-appointments::messages.bookings.event_title'));
        $event->setStart($startDateTime);
        $event->setEnd($endDateTime);
        $event->setConferenceData($conferenceData);

        $description = $this->buildEventDescription($booking);
        if ($description) {
            $event->setDescription($description);
        }

        $created = $calendarApi->events->insert('primary', $event, [
            'conferenceDataVersion' => 1,
        ]);

        $entryPoints = $created->getConferenceData()?->getEntryPoints() ?? [];

        foreach ($entryPoints as $ep) {
            if ($ep->getEntryPointType() === 'video') {
                return $ep->getUri();
            }
        }

        return null;
    }

    protected function buildEventDescription(AppointmentBooking $booking): ?string
    {
        $metadata = $booking->metadata ?? [];
        $excluded = ['meet_link'];

        $lines = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }
            $label = ucfirst(str_replace('_', ' ', $key));
            $lines[] = "{$label}: {$value}";
        }

        return $lines ? implode("\n", $lines) : null;
    }

    protected function makeCalendarService(GoogleClient $client): GoogleCalendarApi
    {
        return new GoogleCalendarApi($client);
    }
}
