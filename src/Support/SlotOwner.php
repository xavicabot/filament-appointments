<?php

namespace XaviCabot\FilamentAppointments\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class SlotOwner
{
    public function __construct(
        public string $type,
        public int | string $id,
    ) {}

    public static function forUser(Authenticatable $user): self
    {
        return new self('user', (int) $user->getAuthIdentifier());
    }

    public function cacheKey(): string
    {
        return $this->type . ':' . $this->id;
    }
}
