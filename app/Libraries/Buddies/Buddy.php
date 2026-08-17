<?php

declare(strict_types=1);

namespace App\Libraries\Buddies;

use Xgp\App\Core\Entity\BuddyEntity;
use Xgp\App\Core\Enumerators\BuddiesStatusEnumerator as BuddiesStatus;

/**
 * Splits a user's buddy rows into confirmed buddies, sent requests and
 * received requests.
 */
class Buddy
{
    /** @var list<BuddyEntity> */
    private array $buddies = [];

    /**
     * @param  array<int, array<string, mixed>>  $buddies
     */
    public function __construct(array $buddies, private int $currentUserId)
    {
        foreach ($buddies as $buddy) {
            $this->buddies[] = new BuddyEntity($buddy);
        }
    }

    /**
     * Players this user sent a still-pending request to.
     *
     * @return list<BuddyEntity>
     */
    public function getSentRequests(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (BuddyEntity $buddy): bool => !$this->isBuddy($buddy) && $this->isOwnRequest($buddy)
        ));
    }

    /**
     * Players who sent a still-pending request to this user.
     *
     * @return list<BuddyEntity>
     */
    public function getReceivedRequests(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (BuddyEntity $buddy): bool => !$this->isBuddy($buddy) && !$this->isOwnRequest($buddy)
        ));
    }

    /**
     * Confirmed buddies.
     *
     * @return list<BuddyEntity>
     */
    public function getBuddies(): array
    {
        return array_values(array_filter(
            $this->buddies,
            fn (BuddyEntity $buddy): bool => $this->isBuddy($buddy)
        ));
    }

    private function isBuddy(BuddyEntity $buddy): bool
    {
        return (int) $buddy->getBuddyStatus() === BuddiesStatus::isBuddy;
    }

    private function isOwnRequest(BuddyEntity $buddy): bool
    {
        return (int) $buddy->getBuddySender() === $this->currentUserId;
    }
}
