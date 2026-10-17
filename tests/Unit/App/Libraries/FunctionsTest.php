<?php

declare(strict_types=1);

namespace Tests\Unit\App\Libraries;

use App\Libraries\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(Functions::class)]
class FunctionsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function emailProvider(): iterable
    {
        yield 'plain address' => ['player@example.com', true];
        yield 'subdomain' => ['a.b@mail.example.org', true];
        yield 'missing tld' => ['player@example', false];
        yield 'not an email' => ['nonsense', false];
    }

    #[DataProvider('emailProvider')]
    public function testValidEmail(string $address, bool $expected): void
    {
        $this->assertSame($expected, Functions::validEmail($address));
    }

    public function testSetImageBuildsAnImgTag(): void
    {
        $this->assertSame('<img src="a.gif" title="img" border="0">', Functions::setImage('a.gif'));
        $this->assertSame(
            '<img src="a.gif" title="Spy" border="0" class="x">',
            Functions::setImage('a.gif', 'Spy', 'class="x"')
        );
    }

    public function testIsCurrentPlanetMatchesOnAllFourCoordinates(): void
    {
        $planet = ['planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 3, 'planet_type' => 1];

        $this->assertTrue(Functions::isCurrentPlanet($planet, $planet));
        $this->assertFalse(Functions::isCurrentPlanet($planet, ['planet_galaxy' => 1, 'planet_system' => 2, 'planet_planet' => 4, 'planet_type' => 1]));
        // a missing coordinate must not throw and must not falsely match
        $this->assertFalse(Functions::isCurrentPlanet($planet, []));
    }
}
