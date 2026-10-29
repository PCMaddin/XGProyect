<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\Planets;
use App\Models\User;
use App\Services\Game\ResearchQueueService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * The laboratory level research uses: the current planet's laboratory, or
 * with the intergalactic research network all laboratories of the player.
 */
#[CoversClass(ResearchQueueService::class)]
class ResearchLabLevelTest extends DatabaseTestCase
{
    public function testWithoutTheNetworkOnlyTheCurrentLaboratoryCounts(): void
    {
        [$user, $planet] = $this->seedPlayer(network: 0);

        $this->assertSame(3, app(ResearchQueueService::class)->labLevel($user, $planet));
    }

    public function testWithTheNetworkAllLaboratoriesOfThePlayerCount(): void
    {
        [$user, $planet] = $this->seedPlayer(network: 1);

        $this->assertSame(3 + 5 + 2, app(ResearchQueueService::class)->labLevel($user, $planet));
    }

    /**
     * @return array{0: User, 1: Planets}
     */
    private function seedPlayer(int $network): array
    {
        $user = new User(['name' => 'Player', 'email' => 'player@example.com', 'password' => 'secret', 'home_planet_id' => 1, 'current_planet' => 1]);
        $user->save();
        DB::table('research')->insert(['research_user_id' => $user->id, 'research_intergalactic_research_network' => $network]);

        // three planets of the player, one of somebody else
        foreach ([[1, $user->id, 3], [2, $user->id, 5], [3, $user->id, 2], [4, $user->id + 1, 7]] as [$planetId, $owner, $lab]) {
            DB::table('planets')->insert(['planet_id' => $planetId, 'planet_user_id' => $owner]);
            DB::table('buildings')->insert(['building_planet_id' => $planetId, 'building_laboratory' => $lab]);
        }

        $planet = Planets::find(1);
        $this->assertInstanceOf(Planets::class, $planet);

        return [$user->fresh() ?? $user, $planet];
    }
}
