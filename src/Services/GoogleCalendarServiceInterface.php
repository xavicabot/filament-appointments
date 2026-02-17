<?php

namespace XaviCabot\FilamentAppointments\Services;

use Google\Client as GoogleClient;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;

interface GoogleCalendarServiceInterface
{
    /**
     * Get an authenticated Google Client for the given connection.
     * Refreshes the token if expired and persists the new token.
     *
     * @throws \RuntimeException if token refresh fails
     */
    public function getAuthenticatedClient(CalendarConnection $connection): GoogleClient;

    /**
     * Sync calendar list from Google to CalendarSource records.
     *
     * @return array<\XaviCabot\FilamentAppointments\Models\CalendarSource>
     */
    public function syncCalendars(CalendarConnection $connection): array;

    /**
     * Fetch busy windows from Google freeBusy API.
     *
     * @param  array<string>  $calendarIds
     * @return array<int, array{start: string, end: string}>
     */
    public function fetchBusyWindows(
        CalendarConnection $connection,
        array $calendarIds,
        string $timeMin,
        string $timeMax,
    ): array;

    /**
     * Sync Google Calendar busy windows as AppointmentBlock records (source = 'google').
     *
     * @return int Number of blocks created.
     */
    public function syncBusyBlocks(
        CalendarConnection $connection,
        string $ownerType,
        int|string $ownerId,
        int $days = 30,
    ): int;

    /**
     * Create a Google Calendar event with Google Meet conference on the owner's primary calendar.
     *
     * @return string|null The Meet join URL, or null if creation failed.
     */
    public function createEventWithMeet(
        CalendarConnection $connection,
        \XaviCabot\FilamentAppointments\Models\AppointmentBooking $booking,
    ): ?string;
}
