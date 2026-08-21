<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\Users;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(Users::class)]
class UsersTest extends TestCase
{
    private function users(): Users
    {
        // With no session set the constructor early-returns (no tick), so this
        // is safe to build in isolation.
        return new Users();
    }

    public function testIsOnVacations(): void
    {
        $users = $this->users();

        $this->assertTrue($users->isOnVacations(['preference_vacation_mode' => 1]));
        $this->assertFalse($users->isOnVacations(['preference_vacation_mode' => 0]));
        // a missing key must not throw
        $this->assertFalse($users->isOnVacations([]));
    }

    public function testIsInactive(): void
    {
        $users = $this->users();

        $this->assertFalse($users->isInactive(['onlinetime' => time()]));
        $this->assertTrue($users->isInactive(['onlinetime' => time() - ONE_WEEK - 100]));
        $this->assertTrue($users->isInactive([]));
    }

    public function testGetterDefaultsAreEmptyArraysWithoutASession(): void
    {
        $users = $this->users();

        $this->assertSame([], $users->getUserData());
        $this->assertSame([], $users->getPlanetData());
    }
}
