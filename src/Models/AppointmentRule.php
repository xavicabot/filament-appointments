<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentRule extends Model
{
    protected $table = 'fa_rules';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'weekday',
        'start_time',
        'end_time',
        'interval_minutes',
        'is_active',
    ];

    protected $casts = [
        'owner_id' => 'int',
        'weekday' => 'int',
        'interval_minutes' => 'int',
        'is_active' => 'bool',
    ];
}
