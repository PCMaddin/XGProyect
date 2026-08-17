<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Adm;

use App\Libraries\Adm\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\UserRanksEnumerator as UserRanks;

#[CoversClass(Permissions::class)]
class PermissionsTest extends TestCase
{
    public function testAdminIsAlwaysAllowed(): void
    {
        $permissions = new Permissions('{}');

        $this->assertTrue($permissions->isAccessAllowed('anything', UserRanks::ADMIN));
    }

    public function testGrantedModuleForRole(): void
    {
        $permissions = new Permissions('{"users":{"2":1},"settings":{"2":0}}');

        $this->assertTrue($permissions->isAccessAllowed('users', 2));
        $this->assertFalse($permissions->isAccessAllowed('settings', 2));
        $this->assertFalse($permissions->isAccessAllowed('unknown', 2));
    }

    public function testInvalidJsonDeniesInsteadOfDying(): void
    {
        $permissions = new Permissions('{not json');

        $this->assertFalse($permissions->isAccessAllowed('users', 2));
    }
}
