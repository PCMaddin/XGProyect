<?php

declare(strict_types=1);

namespace App\Libraries\Adm;

use Xgp\App\Core\Enumerators\UserRanksEnumerator as UserRanks;

/**
 * Admin permission matrix, stored as a JSON string of module -> role -> flag.
 *
 * The legacy version called die() when the JSON failed to decode; this one
 * degrades to an empty (deny-all) matrix instead.
 */
class Permissions
{
    /** @var array<array-key, mixed> */
    private array $permissions = [];

    public function __construct(string $permissions)
    {
        $decoded = json_decode($permissions, true);

        if (is_array($decoded)) {
            $this->permissions = $decoded;
        }
    }

    public function isAccessAllowed(string $module, int $role): bool
    {
        if ($role === UserRanks::ADMIN) {
            return true;
        }

        $modulePermissions = $this->permissions[$module] ?? null;

        return is_array($modulePermissions) && ($modulePermissions[$role] ?? null) === 1;
    }
}
