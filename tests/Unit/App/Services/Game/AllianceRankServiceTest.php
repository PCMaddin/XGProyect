<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Libraries\Alliance\Ranks;
use App\Services\Game\AllianceRankService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

#[CoversClass(AllianceRankService::class)]
class AllianceRankServiceTest extends DatabaseTestCase
{
    /**
     * Regression: the members' rank ids stayed as they were while the ranks
     * after the deleted one moved up, so the members got other ranks.
     */
    public function testTheMembersFollowTheirRanks(): void
    {
        $ranks = $this->seedAlliance();

        (new AllianceRankService())->deleteRank(1, $ranks, 3);

        $this->assertSame(
            ['officer' => 2, 'trader' => Ranks::NEWCOMER, 'diplomat' => 3, 'founder' => Ranks::FOUNDER, 'outsider' => 3],
            DB::table('users')->orderBy('id')->pluck('ally_rank_id', 'name')->all()
        );
        $this->assertSame(['Founder', 'Newcomer', 'Officer', 'Diplomat'], $this->storedRankNames());
    }

    public function testTheFounderAndNewcomerRanksStay(): void
    {
        $ranks = $this->seedAlliance();
        $service = new AllianceRankService();

        $service->deleteRank(1, $ranks, Ranks::FOUNDER);
        $service->deleteRank(1, $ranks, Ranks::NEWCOMER);

        $this->assertSame(['Founder', 'Newcomer', 'Officer', 'Trader', 'Diplomat'], $this->storedRankNames());
        $this->assertSame(0, DB::table('users')->where('name', 'founder')->value('ally_rank_id'));
    }

    private function seedAlliance(): Ranks
    {
        $ranks = new Ranks('[{"rank":"Founder","rights":{}},{"rank":"Newcomer","rights":{}}]');
        $ranks->addNew('Officer');
        $ranks->addNew('Trader');
        $ranks->addNew('Diplomat');

        DB::table('alliance')->insert(['alliance_id' => 1, 'alliance_ranks' => $ranks->getAllRanksAsJsonString()]);

        foreach ([['officer', 1, 2], ['trader', 1, 3], ['diplomat', 1, 4], ['founder', 1, 0], ['outsider', 2, 3]] as $i => [$name, $allyId, $rankId]) {
            DB::table('users')->insert([
                'name' => $name,
                'email' => $name . '@example.com',
                'home_planet_id' => $i + 1,
                'current_planet' => $i + 1,
                'ally_id' => $allyId,
                'ally_rank_id' => $rankId,
            ]);
        }

        return $ranks;
    }

    /**
     * @return list<string>
     */
    private function storedRankNames(): array
    {
        $json = DB::table('alliance')->where('alliance_id', 1)->value('alliance_ranks');
        $this->assertIsString($json);

        return array_column((new Ranks($json))->getAllRanksAsArray(), 'rank');
    }
}
