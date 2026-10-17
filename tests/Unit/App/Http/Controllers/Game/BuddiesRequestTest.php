<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\BuddiesController;
use App\Services\SettingsService;
use App\Services\TimingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\DatabaseTestCase;

/**
 * Regression tests for accepting / removing buddy requests: an unknown id
 * crashed with a TypeError, and any id was accepted, so a player could send
 * "accepted" / "rejected" messages about other players' requests.
 */
#[CoversClass(BuddiesController::class)]
class BuddiesRequestTest extends DatabaseTestCase
{
    private function act(string $action, int $userId, int $bid): RedirectResponse
    {
        $controller = new BuddiesController(new TimingService(new SettingsService()));
        (new ReflectionProperty(BuddiesController::class, 'user'))->setValue($controller, ['id' => $userId]);

        $response = (new ReflectionMethod(BuddiesController::class, $action))
            ->invoke($controller, new Request(['bid' => $bid]));
        $this->assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function request(int $sender, int $receiver): int
    {
        return (int) DB::table('buddys')->insertGetId(['buddy_sender' => $sender, 'buddy_receiver' => $receiver, 'buddy_status' => 0]);
    }

    public function testUnknownRequestsAreIgnored(): void
    {
        $this->act('acceptRequest', 7, 999);
        $this->act('removeRequest', 7, 999);

        $this->assertSame(0, DB::table('messages')->count());
    }

    public function testOnlyTheReceiverCanAccept(): void
    {
        $bid = $this->request(1, 2);

        $this->act('acceptRequest', 1, $bid);
        $this->act('acceptRequest', 3, $bid);
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(0, DB::table('buddys')->where('buddy_id', $bid)->value('buddy_status'));

        $this->act('acceptRequest', 2, $bid);
        $this->assertSame(1, DB::table('messages')->where('message_receiver', 1)->count());
        $this->assertSame(1, DB::table('buddys')->where('buddy_id', $bid)->value('buddy_status'));
    }

    public function testOnlyTheTwoPlayersCanRemove(): void
    {
        $bid = $this->request(1, 2);

        $this->act('removeRequest', 3, $bid);
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(1, DB::table('buddys')->where('buddy_id', $bid)->count());

        $this->act('removeRequest', 2, $bid);
        $this->assertSame(1, DB::table('messages')->where('message_receiver', 1)->count());
        $this->assertSame(0, DB::table('buddys')->where('buddy_id', $bid)->count());
    }
}
