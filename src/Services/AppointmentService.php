<?php

namespace XaviCabot\FilamentAppointments\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use XaviCabot\FilamentAppointments\Mail\AppointmentConfirmationMail;
use XaviCabot\FilamentAppointments\Models\CalendarConnection;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;
use XaviCabot\FilamentAppointments\Support\HasBookings;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

class AppointmentService
{
    public function createBooking(
        SlotOwner $owner,
        string $date,
        string $startTime,
        Authenticatable $client,
        array $metadata = [],
    ): AppointmentBooking {
        $weekday = (int) \Carbon\Carbon::parse($date)->isoWeekday();

        $rule = AppointmentRule::query()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->first();

        $interval = $rule ? (int) $rule->interval_minutes : 30;
        $endTime = \Carbon\Carbon::parse($startTime)->addMinutes($interval)->format('H:i');

        $requireConfirmation = (bool) config('filament-appointments.bookings.require_confirmation', false);
        $status = $requireConfirmation ? 'pending' : 'confirmed';

        $booking = DB::transaction(function () use ($owner, $date, $startTime, $endTime, $client, $status, $metadata) {
            $exists = AppointmentBooking::active()
                ->forOwner($owner)
                ->forDate($date)
                ->where('start_time', $startTime)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new \RuntimeException(
                    __('filament-appointments::messages.bookings.slot_unavailable')
                );
            }

            return AppointmentBooking::create([
                'owner_type' => $owner->type,
                'owner_id' => $owner->id,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'client_type' => $client->getMorphClass(),
                'client_id' => $client->getAuthIdentifier(),
                'status' => $status,
                'metadata' => $metadata ?: null,
            ]);
        });

        $meetLink = $this->tryCreateMeet($owner, $booking);

        if ($meetLink) {
            $booking->update([
                'metadata' => array_merge($booking->metadata ?? [], ['meet_link' => $meetLink]),
            ]);
            $booking->refresh();
        }

        $this->sendEmail($booking, $client);

        return $booking;
    }

    protected function tryCreateMeet(SlotOwner $owner, AppointmentBooking $booking): ?string
    {
        if ($owner->type !== 'user') {
            return null;
        }

        $conn = CalendarConnection::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->first();

        if (! $conn) {
            \Illuminate\Support\Facades\Log::debug('[FTS] tryCreateMeet: no connection found for user_id=' . $owner->id);
            return null;
        }

        if (! $conn->hasScope('https://www.googleapis.com/auth/calendar.events')) {
            \Illuminate\Support\Facades\Log::debug('[FTS] tryCreateMeet: missing calendar.events scope', ['scopes' => $conn->scopes]);
            return null;
        }

        try {
            $service = app(GoogleCalendarServiceInterface::class);

            return $service->createEventWithMeet($conn, $booking);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FTS] tryCreateMeet failed: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    protected function sendEmail(AppointmentBooking $booking, Authenticatable $client): void
    {
        $uses = class_uses_recursive($client);

        if (! isset($uses[HasBookings::class])) {
            return;
        }

        /** @var HasBookings $client */
        $email = $client->getEmailForBooking();

        if (! $email) {
            return;
        }

        Mail::to($email)->send(new AppointmentConfirmationMail($booking, $client));
    }
}
