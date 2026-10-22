<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Missions;

use App\Libraries\Missions\Expedition;
use App\Models\Fleets;
use App\Models\Options;
use App\Services\Game\MerchantService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

/**
 * The expedition result "merchant" (it ended like "nothing" before): as in
 * the original, he comes for free with a random resource and rate; the
 * owner gets the report and the fleet returns.
 */
#[CoversClass(Expedition::class)]
class ExpeditionMerchantTest extends DatabaseTestCase
{
    public function testTheMerchantResultBringsAFreeOffer(): void
    {
        Options::insert(['name' => 'merchant_base_min_exchange_rate', 'value' => '0.7', 'type' => 'float']);
        Options::insert(['name' => 'merchant_base_max_exchange_rate', 'value' => '1', 'type' => 'float']);
        DB::table('premium')->insert(['premium_user_id' => 5, 'premium_dark_matter' => 0]);
        $fleetId = DB::table('fleets')->insertGetId(['fleet_owner' => 5, 'fleet_mission' => 15, 'fleet_mess' => 0, 'fleet_end_galaxy' => 1, 'fleet_end_system' => 2, 'fleet_end_planet' => 16], 'fleet_id');
        $fleet = (array) DB::table('fleets')->where('fleet_id', $fleetId)->first();

        (new ReflectionMethod(Expedition::class, 'resultMerchant'))->invoke(app(Expedition::class), $fleet);

        $offer = app(MerchantService::class)->offer(5);
        $this->assertNotNull($offer);
        $this->assertContains($offer['sell'], MerchantService::RESOURCES);
        $this->assertSame(0, DB::table('premium')->where('premium_user_id', 5)->value('premium_dark_matter'));
        $this->assertSame(1, Fleets::findOrFail($fleetId)->fleet_mess);
        $this->assertSame(__('game/expedition.exp_trader'), DB::table('messages')->where('message_receiver', 5)->value('message_text'));
    }
}
