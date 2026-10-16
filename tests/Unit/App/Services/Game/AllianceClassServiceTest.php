<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Enums\AllianceClass;
use App\Enums\PlayerClass;
use App\Models\Options;
use App\Services\Game\AllianceClassService;
use App\Services\Game\PlayerClassService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Choosing an alliance class: the first choice is free, a change costs the
 * administrator dark matter; every member gets the bonuses.
 */
#[CoversClass(AllianceClassService::class)]
class AllianceClassServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Options::insert(['name' => 'class_change_price', 'value' => '500000', 'type' => 'int']);
        DB::table('alliance')->insert(['alliance_id' => 3, 'alliance_name' => 'Allies', 'alliance_tag' => 'ALL', 'alliance_owner' => 7]);
        DB::table('users')->insert([
            ['id' => 7, 'name' => 'Founder', 'email' => 'f@example.com', 'password' => 'x', 'home_planet_id' => 70, 'current_planet' => 70, 'ally_id' => 3, 'player_class' => 0],
            ['id' => 8, 'name' => 'Member', 'email' => 'm@example.com', 'password' => 'x', 'home_planet_id' => 80, 'current_planet' => 80, 'ally_id' => 3, 'player_class' => 2],
            ['id' => 9, 'name' => 'Loner', 'email' => 'l@example.com', 'password' => 'x', 'home_planet_id' => 90, 'current_planet' => 90, 'ally_id' => 0, 'player_class' => 0],
        ]);
        DB::table('premium')->insert(['premium_user_id' => 7, 'premium_dark_matter' => 600000]);
    }

    public function testTheFirstChoiceIsFreeAChangeCostsDarkMatter(): void
    {
        $classes = app(AllianceClassService::class);
        $this->assertSame(AllianceClass::None, $classes->classOf(3));

        $this->assertNull($classes->choose(3, 7, AllianceClass::Warrior));
        $this->assertSame(AllianceClass::Warrior, $classes->classOf(3));
        $this->assertSame(600000, $this->darkMatter());

        $this->assertSame('al_class_already_chosen', $classes->choose(3, 7, AllianceClass::Warrior));
        $this->assertNull($classes->choose(3, 7, AllianceClass::Trader));
        $this->assertSame(100000, $this->darkMatter());

        $this->assertSame('al_class_no_enough_dark_matter', $classes->choose(3, 7, AllianceClass::Researcher));
        $this->assertSame('al_class_unknown', $classes->choose(3, 7, AllianceClass::None));
        $this->assertSame(AllianceClass::Trader, $classes->classOf(3));
    }

    public function testEveryMemberGetsTheBonuses(): void
    {
        app(AllianceClassService::class)->choose(3, 7, AllianceClass::Warrior);
        $players = app(PlayerClassService::class);

        $member = $players->bonusesOf(8);
        $this->assertSame(PlayerClass::General, $member->player);
        $this->assertSame(AllianceClass::Warrior, $member->alliance);
        $this->assertSame(3, $member->combatTechBonus());
        $this->assertSame(AllianceClass::Warrior, $players->bonusesOf(7)->alliance);
        $this->assertSame(AllianceClass::None, $players->bonusesOf(9)->alliance);
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->where('premium_user_id', 7)->value('premium_dark_matter');
        $this->assertIsNumeric($value);

        return (int) $value;
    }
}
