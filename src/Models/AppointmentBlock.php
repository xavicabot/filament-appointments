<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentBlock extends Model
{
    protected $table = 'fa_blocks';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'date',
        'start_time',
        'end_time',
        'reason',
        'source',
    ];

    protected $casts = [
        'owner_id' => 'int',
        'date' => 'date',
    ];
}
