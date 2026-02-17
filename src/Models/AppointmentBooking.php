<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

class AppointmentBooking extends Model
{
    protected $table = 'fa_bookings';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'date',
        'start_time',
        'end_time',
        'client_type',
        'client_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'owner_id' => 'int',
        'client_id' => 'int',
        'date' => 'date',
        'metadata' => 'array',
    ];

    public function client(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'confirmed']);
    }

    public function scopeExpired(Builder $query, int $ttlHours): Builder
    {
        return $query->pending()
            ->where('created_at', '<', now()->subHours($ttlHours));
    }

    public function scopeForOwner(Builder $query, SlotOwner $owner): Builder
    {
        return $query
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }
}
