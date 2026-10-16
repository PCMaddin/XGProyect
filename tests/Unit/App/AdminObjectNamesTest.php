<?php

declare(strict_types=1);

namespace Tests\Unit\App;

use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Officer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every building, research, ship and defense has a name in the admin
 * lists (the fleet list failed with "Undefined array key 219").
 */
#[CoversNothing]
class AdminObjectNamesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function languages(): array
    {
        return ['de' => ['de'], 'en' => ['en'], 'es' => ['es']];
    }

    #[DataProvider('languages')]
    public function testEveryObjectHasAName(string $language): void
    {
        $names = trans('admin/objects.objects', [], $language);
        $this->assertIsArray($names);

        foreach (app(GameObjectRegistry::class)->all() as $id => $object) {
            if ($object instanceof Officer) {
                continue;
            }

            $this->assertArrayHasKey($id, $names, $language . ': ' . $object->getName());
        }
    }
}
