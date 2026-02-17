<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('filament-appointments::messages.bookings.confirmed_page_title') }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 40px auto; padding: 20px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 32px; }
        h1 { color: #16a34a; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        td { padding: 8px; border: 1px solid #ddd; }
        td:first-child { font-weight: bold; width: 40%; }
        .meet-btn { display: inline-block; padding: 10px 20px; background-color: #1a73e8; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 12px; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        @if($alreadyProcessed)
            <h1 class="muted">{{ __('filament-appointments::messages.bookings.already_processed') }}</h1>
        @else
            <h1>{{ __('filament-appointments::messages.bookings.confirmed_heading') }}</h1>
            <p>{{ __('filament-appointments::messages.bookings.confirmed_body') }}</p>
        @endif

        <table>
            <tr>
                <td>{{ __('filament-appointments::messages.date') }}</td>
                <td>{{ $booking->date->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td>{{ __('filament-appointments::messages.start_time') }}</td>
                <td>{{ $booking->start_time }}</td>
            </tr>
            <tr>
                <td>{{ __('filament-appointments::messages.end_time') }}</td>
                <td>{{ $booking->end_time }}</td>
            </tr>
        </table>

        @if($meetLink)
            <p>{{ __('filament-appointments::messages.bookings.meet_note') }}</p>
            <a href="{{ $meetLink }}" class="meet-btn">{{ __('filament-appointments::messages.bookings.join_meet') }}</a>
        @endif
    </div>
</body>
</html>
