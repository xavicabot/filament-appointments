<?php

namespace XaviCabot\FilamentAppointments\Support;

use Illuminate\Database\Eloquent\Builder;
use XaviCabot\FilamentAppointments\Models\AppointmentRule;

trait HasAppointments
{
    public function getSlotOwnerType(): string
    {
        return 'user';
    }

    public function toSlotOwner(): SlotOwner
    {
        return new SlotOwner($this->getSlotOwnerType(), $this->getKey());
    }

    public function scopeWithAppointments(Builder $query): Builder
    {
        return $query->whereIn(
            $this->getKeyName(),
            AppointmentRule::where('owner_type', (new static)->getSlotOwnerType())
                ->where('is_active', true)
                ->distinct()
                ->pluck('owner_id')
        );
    }
}
