<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarBusyCache extends Model
{
    protected $table = 'fa_busy_cache';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'date',
        'calendars_hash',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'owner_id' => 'int',
        'date' => 'date',
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
