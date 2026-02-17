<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>{{ __('filament-appointments::messages.bookings.email_pending_heading') }}</h2>

    <p>{{ __('filament-appointments::messages.bookings.email_greeting', ['name' => $client->getNameForBooking()]) }}</p>

    <p>{{ __('filament-appointments::messages.bookings.email_pending_body') }}</p>

    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">{{ __('filament-appointments::messages.date') }}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ $booking->date->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">{{ __('filament-appointments::messages.start_time') }}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ $booking->start_time }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">{{ __('filament-appointments::messages.end_time') }}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ $booking->end_time }}</td>
        </tr>
    </table>

    <p>
        <a href="{{ $confirmUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #16a34a; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
            {{ __('filament-appointments::messages.bookings.confirm_button') }}
        </a>
    </p>

    <p style="color: #666; font-size: 14px;">
        {{ __('filament-appointments::messages.bookings.email_expires_note', ['hours' => config('filament-appointments.bookings.confirmation_ttl_hours', 24)]) }}
    </p>

    @if($meetLink)
        <p>{{ __('filament-appointments::messages.bookings.meet_note') }}</p>
        <p>
            <a href="{{ $meetLink }}" style="display: inline-block; padding: 10px 20px; background-color: #1a73e8; color: #ffffff; text-decoration: none; border-radius: 4px;">
                {{ __('filament-appointments::messages.bookings.join_meet') }}
            </a>
        </p>
    @endif
</body>
</html>
