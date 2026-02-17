<?php

namespace XaviCabot\FilamentAppointments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;

class AppointmentConfirmationController extends Controller
{
    public function confirm(Request $request, AppointmentBooking $booking)
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('filament-appointments::messages.bookings.invalid_signature'));
        }

        if ($booking->status !== 'pending') {
            return view('filament-appointments::bookings.confirmed', [
                'booking' => $booking,
                'meetLink' => $booking->metadata['meet_link'] ?? null,
                'alreadyProcessed' => true,
            ]);
        }

        $booking->update(['status' => 'confirmed']);

        return view('filament-appointments::bookings.confirmed', [
            'booking' => $booking,
            'meetLink' => $booking->metadata['meet_link'] ?? null,
            'alreadyProcessed' => false,
        ]);
    }
}
