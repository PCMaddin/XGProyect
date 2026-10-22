<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\ResearchController;
use App\Models\Planets;
use App\Models\ResearchQueue;
use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(ResearchController::class)]
class ResearchControllerTest extends TestCase
{
    public function testCommanderUsesTopQueueInsteadOfRowCountdownForActiveResearch(): void
    {
        $controller = $this->buildController();
        $planet = new Planets(['planet_id' => 7]);
        $activeItem = new ResearchQueue([
            'tech_id' => 106,
            'planet_id' => 7,
            'end_time' => time() + 300,
        ]);

        $result = $this->callGetActionLink(
            $controller,
            techId: 106,
            currentLevel: 0,
            planet: $planet,
            activeItem: $activeItem,
            workingPlanet: null,
            commanderActive: true,
            blocked: false,
            queueFull: false,
        );

        $this->assertStringContainsString('game.php?page=research&cmd=search&tech=106', $result);
        $this->assertStringNotContainsString('id="brp"', $result);
    }

    public function testActiveResearchShowsRowCountdownWithoutCommander(): void
    {
        $controller = $this->buildController();
        $planet = new Planets([
            'planet_id' => 7,
            'planet_galaxy' => 1,
            'planet_system' => 2,
            'planet_planet' => 3,
        ]);
        $activeItem = new ResearchQueue([
            'tech_id' => 106,
            'planet_id' => 7,
            'end_time' => time() + 300,
        ]);

        $result = $this->callGetActionLink(
            $controller,
            techId: 106,
            currentLevel: 0,
            planet: $planet,
            activeItem: $activeItem,
            workingPlanet: null,
            commanderActive: false,
            blocked: false,
            queueFull: false,
        );

        $this->assertStringContainsString('id="brp"', $result);
        $this->assertStringContainsString('game.php?page=research&cmd=cancel&tech=106', $result);
    }

    private function buildController(): ResearchController
    {
        return new ResearchController(
            registry: $this->createStub(\App\Core\GameObjects\GameObjectRegistry::class),
            developmentsService: $this->createStub(\App\Services\Game\Formulas\DevelopmentsService::class),
            formatService: new FormatService(),
            developmentDataService: $this->createStub(\App\Services\Game\DevelopmentDataService::class),
            officerService: $this->createStub(\App\Services\Game\Formulas\OfficerService::class),
            settingsService: $this->createStub(\App\Services\SettingsService::class),
            researchQueueService: $this->createStub(\App\Services\Game\ResearchQueueService::class),
            timingService: $this->createStub(\App\Services\TimingService::class),
        );
    }

    private function callGetActionLink(
        ResearchController $controller,
        int $techId,
        int $currentLevel,
        Planets $planet,
        ?ResearchQueue $activeItem,
        ?Planets $workingPlanet,
        bool $commanderActive,
        bool $blocked,
        bool $queueFull,
    ): string {
        $method = new ReflectionMethod(ResearchController::class, 'getActionLink');

        $result = $method->invoke(
            $controller,
            $techId,
            $currentLevel,
            $planet,
            $activeItem,
            $workingPlanet,
            $commanderActive,
            $blocked,
            $queueFull,
        );

        $this->assertIsString($result);

        return $result;
    }
}
