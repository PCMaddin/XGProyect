<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Enums\PlayerClass;
use App\Models\Options;
use App\Services\Game\PlayerClassService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Choosing a player class: the first choice is free, a change costs dark
 * matter; generals' moons get 5 fields, which go again on a change.
 */
#[CoversClass(PlayerClassService::class)]
class PlayerClassServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Options::insert(['name' => 'class_change_price', 'value' => '500000', 'type' => 'int']);
        DB::table('users')->insert(['id' => 7, 'name' => 'Player', 'email' => 'p@example.com', 'password' => 'x', 'home_planet_id' => 70, 'current_planet' => 70]);
        DB::table('premium')->insert(['premium_user_id' => 7, 'premium_dark_matter' => 600000]);
        DB::table('planets')->insert([
            ['planet_id' => 70, 'planet_user_id' => 7, 'planet_type' => 1, 'planet_field_max' => 163],
            ['planet_id' => 71, 'planet_user_id' => 7, 'planet_type' => 3, 'planet_field_max' => 1],
        ]);
    }

    public function testTheFirstChoiceIsFreeAChangeCostsDarkMatter(): void
    {
        $classes = app(PlayerClassService::class);
        $this->assertSame(PlayerClass::None, $classes->classOf(7));

        $this->assertNull($classes->choose(7, PlayerClass::General));
        $this->assertSame(PlayerClass::General, app(PlayerClassService::class)->classOf(7));
        $this->assertSame(600000, $this->darkMatter());
        $this->assertSame([163, 6], $this->fields());

        $this->assertSame('cc_already_chosen', $classes->choose(7, PlayerClass::General));
        $this->assertNull($classes->choose(7, PlayerClass::Discoverer));
        $this->assertSame(100000, $this->darkMatter());
        $this->assertSame([163, 1], $this->fields());

        $this->assertSame('cc_no_enough_dark_matter', $classes->choose(7, PlayerClass::Collector));
        $this->assertSame('cc_unknown_class', $classes->choose(7, PlayerClass::None));
        $this->assertSame(PlayerClass::Discoverer, app(PlayerClassService::class)->classOf(7));
    }

    private function darkMatter(): int
    {
        $value = DB::table('premium')->value('premium_dark_matter');
        $this->assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return list<int>
     */
    private function fields(): array
    {
        return array_values(DB::table('planets')->orderBy('planet_id')->pluck('planet_field_max')->map(fn ($v): int => is_numeric($v) ? (int) $v : -1)->all());
    }
}
