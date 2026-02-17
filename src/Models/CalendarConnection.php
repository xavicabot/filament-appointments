<?php

namespace XaviCabot\FilamentAppointments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarConnection extends Model
{
    protected $table = 'fa_connections';

    protected $fillable = [
        'user_id',
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'status',
    ];

    protected $casts = [
        'user_id' => 'int',
        'expires_at' => 'datetime',
        'scopes' => 'array',
    ];

    public function calendarSources(): HasMany
    {
        return $this->hasMany(CalendarSource::class, 'connection_id');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
