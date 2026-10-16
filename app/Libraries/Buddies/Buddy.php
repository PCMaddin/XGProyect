<?php

declare(strict_types=1);

namespace App\Libraries\Buddies;

use App\Support\Concerns\HydratesModels;
use App\Models\Buddys;
use App\Core\Enumerators\BuddiesStatusEnumerator as BuddiesStatus;

/**
 * Splits a user's buddy rows into confirmed buddies, sent requests and
 * received requests.
 */
class Buddy
{
    use HydratesModels;

    /** @var list<Buddys> */
    private array $buddies = [];

    /**
     * @param  array<int, array<string, mixed>>  $buddies
     */
    public function __construct(array $buddies, private int $currentUserId)
    {
        foreach ($buddies as $buddy) {
            $this->buddies[] = self::hydrateRow(Buddys::class, $buddy);
        }
    }

    /**
     * Players this user sent a still-pending request to.
     *
     * @return list<Buddys>
     */
    public function getSentRequests(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (Buddys $buddy): bool => !$this->isBuddy($buddy) && $this->isOwnRequest($buddy)
        ));
    }

    /**
     * Players who sent a still-pending request to this user.
     *
     * @return list<Buddys>
     */
    public function getReceivedRequests(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (Buddys $buddy): bool => !$this->isBuddy($buddy) && !$this->isOwnRequest($buddy)
        ));
    }

    /**
     * Confirmed buddies.
     *
     * @return list<Buddys>
     */
    public function getBuddies(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (Buddys $buddy): bool => $this->isBuddy($buddy)
        ));
    }

    private function isBuddy(Buddys $buddy): bool
    {
        return (int) $buddy->buddy_status === BuddiesStatus::IS_BUDDY;
    }

    private function isOwnRequest(Buddys $buddy): bool
    {
        return (int) $buddy->buddy_sender === $this->currentUserId;
    }
}
