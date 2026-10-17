<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries\Users;

use App\Libraries\Users\Shortcuts;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(Shortcuts::class)]
class ShortcutsTest extends TestCase
{
    public function testNullOrEmptyInputYieldsNoShortcuts(): void
    {
        $this->assertSame([], (new Shortcuts(null))->getAllAsArray());
        $this->assertSame([], (new Shortcuts(''))->getAllAsArray());
    }

    public function testInvalidJsonDegradesToEmptyInsteadOfDying(): void
    {
        $this->assertSame([], (new Shortcuts('{not valid json'))->getAllAsArray());
    }

    public function testAddNewNormalisesAndAppends(): void
    {
        $shortcuts = new Shortcuts(null);
        $result = $shortcuts->addNew('Base', 1, 2, 3, 1);

        $this->assertSame([['name' => 'Base', 'g' => 1, 's' => 2, 'p' => 3, 'pt' => 1]], $result);
    }

    public function testAddNewRejectsEmptyName(): void
    {
        $shortcuts = new Shortcuts(null);

        $this->assertSame([], $shortcuts->addNew('', 1, 2, 3, 1));
    }

    public function testGetByIdReturnsArrayNotZeroOnMiss(): void
    {
        $shortcuts = new Shortcuts(null);

        // legacy returned int 0 here despite an array return type
        $this->assertSame([], $shortcuts->getById(99));
    }

    public function testLoadedEntriesAreNormalisedToTheFixedShape(): void
    {
        $raw = json_encode([['name' => 'A', 'g' => '4', 's' => '5', 'p' => '6', 'pt' => '3', 'junk' => 'x']]);
        $this->assertIsString($raw);

        $shortcuts = new Shortcuts($raw);

        $this->assertSame(
            [['name' => 'A', 'g' => 4, 's' => 5, 'p' => 6, 'pt' => 3]],
            $shortcuts->getAllAsArray()
        );
    }

    public function testJsonRoundTrip(): void
    {
        $shortcuts = new Shortcuts(null);
        $shortcuts->addNew('Home', 1, 1, 1, 1);

        $reloaded = new Shortcuts($shortcuts->getAllAsJsonString());

        $this->assertSame($shortcuts->getAllAsArray(), $reloaded->getAllAsArray());
    }
}
