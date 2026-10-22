<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Http\Controllers\Game\OverviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(OverviewController::class)]
class OverviewControllerTest extends TestCase
{
    public function testPutOverwritesWithRenderedContentButKeepsEmptySlots(): void
    {
        $controller = app(OverviewController::class);
        $method = new ReflectionMethod(OverviewController::class, 'put');

        $rows = [];

        // an empty result only initialises the slot
        $method->invokeArgs($controller, [&$rows, 100, 5, '']);
        $this->assertSame(['100_5' => ''], $rows);

        // a rendered table fills the slot
        $method->invokeArgs($controller, [&$rows, 100, 5, '<fleet-a>']);
        $this->assertSame(['100_5' => '<fleet-a>'], $rows);

        // a later rendered table for the same slot overwrites it (last write wins)
        $method->invokeArgs($controller, [&$rows, 100, 5, '<fleet-b>']);
        $this->assertSame(['100_5' => '<fleet-b>'], $rows);

        // an empty result does not wipe an already-rendered slot
        $method->invokeArgs($controller, [&$rows, 100, 5, '']);
        $this->assertSame(['100_5' => '<fleet-b>'], $rows);
    }
}
