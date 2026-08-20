<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\Formulas;
use App\Services\Game\Formulas\FormulasService;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The static facade is a bridge kept for the not-yet-migrated legacy engine;
 * it must simply forward to the resolved {@see FormulasService}.
 */
#[CoversClass(Formulas::class)]
class FormulasTest extends TestCase
{
    public function testFacadeForwardsToTheService(): void
    {
        $service = $this->app->make(FormulasService::class);

        $this->assertSame($service->missileRange(3), Formulas::missileRange(3));
        $this->assertSame($service->phalanxRange(5), Formulas::phalanxRange(5));
        $this->assertSame($service->getIonTechnologyBonus(5), Formulas::getIonTechnologyBonus(5));
        $this->assertSame(
            $service->getPlasmaTechnologyBonus(10, 'metal'),
            Formulas::getPlasmaTechnologyBonus(10, 'metal')
        );
        $this->assertSame($service->getDevelopmentCost(100, 2.0, 3), Formulas::getDevelopmentCost(100, 2.0, 3));
    }
}
