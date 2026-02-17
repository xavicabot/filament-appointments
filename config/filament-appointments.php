<?php

return [
    /**
     * Route prefix for JSON endpoints (slots + calendar actions).
     */
    'route_prefix' => 'filament-appointments',

    /**
     * Whether to auto-register admin resources in the Filament panel.
     */
    'register_resources' => true,

    /**
     * The Eloquent model that owns time slot schedules.
     * Used in admin resources to populate owner dropdowns.
     * The model should use the HasAppointments trait.
     */
    'owner_model' => null,

    /**
     * The attribute on the owner model to display in dropdowns (e.g. 'name', 'email').
     */
    'owner_label' => 'name',

    /**
     * Default timezone used to generate / normalize time slots.
     */
    'timezone' => 'Europe/Madrid',

    /**
     * Slot resolver class. Must implement: forDate(string $date, SlotOwner $owner): array
     */
    'resolver' => \XaviCabot\FilamentAppointments\Support\SlotResolver::class,

    /**
     * Bookings:
     * - require_confirmation: if true, bookings start as "pending" and client must confirm via signed URL.
     * - confirmation_ttl_hours: hours before an unconfirmed pending booking is auto-cancelled.
     */
    'bookings' => [
        'require_confirmation' => false,
        'confirmation_ttl_hours' => 24,
    ],

    /**
     * Google Calendar sync:
     * - cache_ttl_seconds: caches busy windows per owner/date/calendars set.
     */
    'google' => [
        'cache_ttl_seconds' => 120,
    ],
];
