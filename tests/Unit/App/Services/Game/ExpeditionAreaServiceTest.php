<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Services\Game\ExpeditionAreaService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\DatabaseTestCase;

/**
 * Busy expedition areas, as in the original: each expedition adds 1 to the
 * load of its system, which recovers by 10 a day; above 10 expeditions
 * finding nothing gets likelier, a longer holding time counteracts it.
 */
#[CoversClass(ExpeditionAreaService::class)]
class ExpeditionAreaServiceTest extends DatabaseTestCase
{
    private const DAY = 86400;

    public function testEachExpeditionAddsToTheLoadOfItsSystem(): void
    {
        $this->assertSame(0.0, $this->areas()->visit(1, 5, 1000));
        $this->assertSame(1.0, $this->areas()->visit(1, 5, 1000));
        $this->assertSame(2.0, $this->areas()->visit(1, 5, 1000));
        // another system is fresh
        $this->assertSame(0.0, $this->areas()->visit(1, 6, 1000));
    }

    public function testTheLoadRecoversByTenADay(): void
    {
        DB::table('expedition_areas')->insert(['galaxy' => 2, 'system' => 9, 'load' => 25, 'updated_at' => 1000]);

        $this->assertEqualsWithDelta(20.0, $this->areas()->visit(2, 9, 1000 + self::DAY / 2), 1e-9);
        // 21 left after half a day; a day later 11
        $this->assertEqualsWithDelta(11.0, $this->areas()->visit(2, 9, 1000 + self::DAY * 3 / 2), 1e-9);
        // an earlier arrival handled later does not turn the clock back
        $this->assertEqualsWithDelta(12.0, $this->areas()->visit(2, 9, 1000), 1e-9);
        $this->assertSame(0.0, ExpeditionAreaService::recovered(5, 0, self::DAY));
    }

    public function testBusyAreasMoreOftenFindNothing(): void
    {
        $areas = $this->areas();

        $this->assertSame(0.0, $areas->nothingChance(10, 3600));
        $this->assertEqualsWithDelta(0.3, $areas->nothingChance(25, 3600), 1e-9);
        $this->assertEqualsWithDelta(0.9, $areas->nothingChance(500, 3600), 1e-9);
        // each further hour of holding time is one more try
        $this->assertEqualsWithDelta(0.09, $areas->nothingChance(25, 2 * 3600), 1e-9);
        $this->assertEqualsWithDelta(0.3, $areas->nothingChance(25, 600), 1e-9);
    }

    public function testTheLogbookTellsHowBusyTheAreaIs(): void
    {
        $areas = $this->areas();

        $this->assertContains($areas->logbookKey(0), ['exp_status_fresh_1', 'exp_status_fresh_2']);
        $this->assertContains($areas->logbookKey(10), ['exp_status_fresh_1', 'exp_status_fresh_2']);
        $this->assertSame('exp_status_busy', $areas->logbookKey(10.5));
        $this->assertSame('exp_status_crowded', $areas->logbookKey(30));
        $this->assertSame('exp_status_overrun', $areas->logbookKey(51));

        foreach (['de', 'en', 'es', 'fr', 'pt_BR', 'nl', 'it', 'pl', 'ja'] as $language) {
            foreach (['exp_status_fresh_1', 'exp_status_fresh_2', 'exp_status_busy', 'exp_status_crowded', 'exp_status_overrun'] as $key) {
                $this->assertNotSame('game/expedition.' . $key, __('game/expedition.' . $key, [], $language));
            }
        }
    }

    private function areas(): ExpeditionAreaService
    {
        return app(ExpeditionAreaService::class);
    }
}
