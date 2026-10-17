<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Game;

use App\Models\Preferences;
use App\Services\Game\PreferencesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreferencesService::class)]
class PreferencesServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function names(): array
    {
        return [
            'letters and digits' => ['Player42', true],
            'twenty characters' => [str_repeat('a', 20), true],
            'three characters' => ['abc', false],
            'twenty-one characters' => [str_repeat('a', 21), false],
            'markup' => ['<svg/onload=alert``>', false],
            'quote' => ["O'Neil", false],
            'space' => ['Dark Lord', false],
            'non-ascii' => ['Spieler€', false],
        ];
    }

    /**
     * Regression: only the length was checked, so a name could carry markup
     * that ran in other players' browsers.
     */
    #[DataProvider('names')]
    public function testOnlyAsciiLettersAndDigitsAreValidNicknames(string $name, bool $valid): void
    {
        $this->assertSame($valid, (new PreferencesService(new Preferences()))->isValidNickname($name));
    }
}
