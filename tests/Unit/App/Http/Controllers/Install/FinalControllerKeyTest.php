<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Install;

use App\Http\Controllers\Install\Steps\FinalController;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression: installations from the release package kept the APP_KEY of
 * .env.example, the same public key on every server.
 */
#[CoversClass(FinalController::class)]
class FinalControllerKeyTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: int}>
     */
    public static function keys(): array
    {
        return [
            'public key of .env.example' => [self::publicKey(), 1],
            'no key' => [null, 1],
            "the server's own key" => ['base64:' . base64_encode(str_repeat('k', 32)), 0],
        ];
    }

    #[DataProvider('keys')]
    public function testOnlyAPublicOrMissingKeyIsReplaced(?string $key, int $generated): void
    {
        config(['app.key' => $key]);
        Artisan::shouldReceive('call')->with('key:generate', ['--force' => true])->times($generated);

        $controller = (new ReflectionClass(FinalController::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(FinalController::class, 'replacePublicAppKey'))->invoke($controller);
    }

    private static function publicKey(): string
    {
        $key = (new ReflectionClass(FinalController::class))->getConstant('PUBLIC_APP_KEY');
        self::assertIsString($key);
        self::assertStringContainsString('APP_KEY=' . $key . "\n", (string) file_get_contents(dirname(__DIR__, 6) . '/.env.example'));

        return $key;
    }
}
