<?php

namespace XaviCabot\FilamentAppointments\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use XaviCabot\FilamentAppointments\Models\AppointmentBooking;

class AppointmentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $confirmUrl = null;

    public ?string $meetLink = null;

    public function __construct(
        public AppointmentBooking $booking,
        public Authenticatable $client,
    ) {
        $this->meetLink = $booking->metadata['meet_link'] ?? null;

        if ($booking->status === 'pending') {
            $ttlHours = (int) config('filament-appointments.bookings.confirmation_ttl_hours', 24);

            $this->confirmUrl = URL::temporarySignedRoute(
                'filament-appointments.bookings.confirm',
                now()->addHours($ttlHours),
                ['booking' => $booking->id],
            );
        }
    }

    public function envelope(): Envelope
    {
        $isPending = $this->booking->status === 'pending';

        $subject = $isPending
            ? __('filament-appointments::messages.bookings.email_pending_subject')
            : __('filament-appointments::messages.bookings.email_confirmed_subject');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $isPending = $this->booking->status === 'pending';

        return new Content(
            view: $isPending
                ? 'filament-appointments::emails.appointment-pending'
                : 'filament-appointments::emails.appointment-confirmed',
        );
    }
}
