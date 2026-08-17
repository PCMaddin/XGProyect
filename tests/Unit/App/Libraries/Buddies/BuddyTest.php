<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Buddies;

use App\Libraries\Buddies\Buddy;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Xgp\App\Core\Enumerators\BuddiesStatusEnumerator as BuddiesStatus;

#[CoversClass(Buddy::class)]
class BuddyTest extends TestCase
{
    private const CURRENT_USER = 10;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            // confirmed buddy
            ['buddy_id' => 1, 'buddy_sender' => 10, 'buddy_receiver' => 20, 'buddy_status' => BuddiesStatus::isBuddy],
            // request this user sent, still pending
            ['buddy_id' => 2, 'buddy_sender' => 10, 'buddy_receiver' => 30, 'buddy_status' => BuddiesStatus::isNotBuddy],
            // request this user received, still pending
            ['buddy_id' => 3, 'buddy_sender' => 40, 'buddy_receiver' => 10, 'buddy_status' => BuddiesStatus::isNotBuddy],
        ];
    }

    public function testConfirmedBuddies(): void
    {
        $buddies = (new Buddy($this->rows(), self::CURRENT_USER))->getBuddies();

        $this->assertCount(1, $buddies);
        $this->assertSame(1, $buddies[0]->getBuddyId());
    }

    public function testSentRequestsAreOwnPendingRequests(): void
    {
        $sent = (new Buddy($this->rows(), self::CURRENT_USER))->getSentRequests();

        $this->assertCount(1, $sent);
        $this->assertSame(2, $sent[0]->getBuddyId());
    }

    public function testReceivedRequestsArePendingRequestsFromOthers(): void
    {
        $received = (new Buddy($this->rows(), self::CURRENT_USER))->getReceivedRequests();

        $this->assertCount(1, $received);
        $this->assertSame(3, $received[0]->getBuddyId());
    }

    public function testEmptyInputYieldsEmptyLists(): void
    {
        $buddy = new Buddy([], self::CURRENT_USER);

        $this->assertSame([], $buddy->getBuddies());
        $this->assertSame([], $buddy->getSentRequests());
        $this->assertSame([], $buddy->getReceivedRequests());
    }
}
