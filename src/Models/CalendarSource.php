<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSource extends Model
{
    protected $table = 'fa_sources';

    protected $fillable = [
        'connection_id',
        'external_calendar_id',
        'name',
        'included',
        'primary',
    ];

    protected $casts = [
        'connection_id' => 'int',
        'included' => 'bool',
        'primary' => 'bool',
    ];
}
