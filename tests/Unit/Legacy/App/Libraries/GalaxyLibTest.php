<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\App\Libraries;

use App\Libraries\GalaxyLib;
use App\Services\FormatService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class GalaxyLibTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DPATH')) {
            define('DPATH', 'assets/upload/skins/xgproyect/');
        }
    }

    public function testActionsBlockSpyIconCancelsDefaultAnchorNavigation(): void
    {
        $galaxyLib = $this->makeGalaxyLib();

        $this->setPrivateProperty($galaxyLib, 'rowData', [
            'id' => 2,
            'authlevel' => 0,
            'preference_vacation_mode' => 0,
            'planet_galaxy' => 1,
        ]);

        $method = new ReflectionMethod(GalaxyLib::class, 'actionsBlock');

        $html = $method->invoke($galaxyLib);

        $this->assertIsString($html);

        $this->assertStringContainsString(
            'onclick="javascript:doit(6, 1, 2, 3, 1, 4); return false;"',
            $html
        );
    }

    public function testPopupSpyLinkCancelsDefaultAnchorNavigation(): void
    {
        $galaxyLib = $this->makeGalaxyLib();

        $method = new ReflectionMethod(GalaxyLib::class, 'spyLink');

        $html = $method->invoke($galaxyLib, GalaxyLib::MOON_TYPE);

        $this->assertIsString($html);

        $this->assertStringContainsString(
            "onclick=\\'javascript:doit(6, 1, 2, 3, 3, 4); return false;\\'",
            $html
        );
    }

    public function testAttackLinkUsesTheEncodedSeparatorStyle(): void
    {
        $galaxyLib = $this->makeGalaxyLib();
        $method = new ReflectionMethod(GalaxyLib::class, 'attackLink');

        $html = $method->invoke($galaxyLib, GalaxyLib::PLANET_TYPE);
        $this->assertIsString($html);

        // attack keeps the legacy &amp; separators and target_mission=1
        $this->assertStringContainsString('page=fleet1&galaxy=1&amp;system=2&amp;planet=3&amp;planettype=1&amp;target_mission=1', $html);
    }

    public function testTransportLinkUsesPlainSeparators(): void
    {
        $galaxyLib = $this->makeGalaxyLib();
        $method = new ReflectionMethod(GalaxyLib::class, 'transportLink');

        $html = $method->invoke($galaxyLib, GalaxyLib::PLANET_TYPE);
        $this->assertIsString($html);

        // transport uses plain & separators and target_mission=3
        $this->assertStringContainsString('page=fleet1&galaxy=1&system=2&planet=3&planettype=1&target_mission=3', $html);
        $this->assertStringNotContainsString('&amp;', $html);
    }

    private function makeGalaxyLib(): GalaxyLib
    {
        $reflectionClass = new ReflectionClass(GalaxyLib::class);
        $galaxyLib = $reflectionClass->newInstanceWithoutConstructor();

        $this->setPrivateProperty($galaxyLib, 'user', [
            'id' => 1,
            'ally_id' => 0,
            'current_planet' => 10,
            'preference_spy_probes' => 4,
            'research_impulse_drive' => 0,
        ]);
        $this->setPrivateProperty($galaxyLib, 'currentPlanet', [
            'defense_interplanetary_missile' => 0,
            'planet_galaxy' => 1,
        ]);
        $this->setPrivateProperty($galaxyLib, 'galaxy', 1);
        $this->setPrivateProperty($galaxyLib, 'system', 2);
        $this->setPrivateProperty($galaxyLib, 'planet', 3);
        $this->setPrivateProperty($galaxyLib, 'formatService', app(FormatService::class));

        return $galaxyLib;
    }

    private function setPrivateProperty(GalaxyLib $galaxyLib, string $property, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty(GalaxyLib::class, $property);
        $reflectionProperty->setValue($galaxyLib, $value);
    }
}
