<?php

namespace XaviCabot\FilamentAppointments\Tests\Unit\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPUnit\Framework\TestCase;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

class SlotOwnerTest extends TestCase
{
    public function test_forUser_creates_user_owner(): void
    {
        $user = new class extends Authenticatable {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };

        $owner = SlotOwner::forUser($user);

        $this->assertSame('user', $owner->type);
        $this->assertSame(42, $owner->id);
    }

    public function test_cacheKey_format(): void
    {
        $owner = new SlotOwner('user', 7);

        $this->assertSame('user:7', $owner->cacheKey());
    }
}
