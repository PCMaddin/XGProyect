<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
use App\Models\Fleets;
use App\Services\Game\MerchantService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * The expedition result "merchant" (it ended like "nothing" before): the
 * owner's next merchant call is free, he gets the report and the fleet
 * returns.
 */
#[CoversClass(Expedition::class)]
class ExpeditionMerchantTest extends DatabaseTestCase
{
    public function testTheMerchantResultMakesTheNextCallFree(): void
    {
        DB::table('premium')->insert(['premium_user_id' => 5]);
        $fleetId = DB::table('fleets')->insertGetId(['fleet_owner' => 5, 'fleet_mission' => 15, 'fleet_mess' => 0, 'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => 16], 'fleet_id');
        $fleet = (array) DB::table('fleets')->where('fleet_id', $fleetId)->first();

        (new ReflectionMethod(Expedition::class, 'resultMerchant'))->invoke(app(Expedition::class), $fleet);

        $this->assertTrue(app(MerchantService::class)->hasFreeCall(5));
        $this->assertSame(1, Fleets::findOrFail($fleetId)->fleet_mess);
        $this->assertSame(__('game/expedition.exp_trader'), DB::table('messages')->where('message_receiver', 5)->value('message_text'));
    }
}
