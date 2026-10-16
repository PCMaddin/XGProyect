<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Controllers\Game;

use App\Services\Game\AllianceRankService;
use App\Http\Controllers\Game\AllianceController;
use App\Services\FormatService;
use App\Services\SettingsService;
use App\Services\TimingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(AllianceController::class)]
class AllianceControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int, 2: string}>
     */
    public static function accessProvider(): iterable
    {
        yield 'no alliance, no request is public' => [0, 0, 'public'];
        yield 'no alliance but pending request awaits approval' => [0, 42, 'awaitingApproval'];
        yield 'in an alliance is member' => [7, 0, 'isMember'];
    }

    #[DataProvider('accessProvider')]
    public function testUserAccessDependsOnMembershipAndPendingRequest(int $allyId, int $allyRequest, string $expected): void
    {
        $controller = $this->controllerWithUser(['ally_id' => $allyId, 'ally_request' => $allyRequest]);

        $method = new ReflectionMethod(AllianceController::class, 'userAccess');

        $this->assertSame($expected, $method->invoke($controller));
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: string, 3: bool}>
     */
    public static function pageProvider(): iterable
    {
        yield 'public may open make' => [0, 0, 'make', true];
        yield 'public may not open admin' => [0, 0, 'admin', false];
        yield 'awaiting may open ainfo' => [0, 5, 'ainfo', true];
        yield 'awaiting may not open make' => [0, 5, 'make', false];
        yield 'member may open admin' => [3, 0, 'admin', true];
        yield 'member may not open make' => [3, 0, 'make', false];
        yield 'unknown section is rejected' => [3, 0, 'bogus', false];
    }

    #[DataProvider('pageProvider')]
    public function testIsPageAllowedRespectsAccessLevel(int $allyId, int $allyRequest, string $section, bool $expected): void
    {
        $controller = $this->controllerWithUser(['ally_id' => $allyId, 'ally_request' => $allyRequest]);

        $method = new ReflectionMethod(AllianceController::class, 'isPageAllowed');

        $this->assertSame($expected, $method->invoke($controller, $section));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function labelProvider(): iterable
    {
        yield 'plain name' => ['Dark Empire', 'name', true];
        yield 'plain tag' => ['DE-1', 'tag', true];
        yield 'too short' => ['DE', 'tag', false];
        yield 'markup in name' => ['<svg onload=x>', 'name', false];
        yield 'quote in tag' => ['A"B', 'tag', false];
        yield 'ampersand' => ['A&B Corp', 'name', false];
    }

    /**
     * Regression (stored XSS): alliance names and tags were only checked for
     * their length.
     */
    #[DataProvider('labelProvider')]
    public function testAllianceNamesAndTagsMustBePlainText(string $label, string $kind, bool $valid): void
    {
        $error = (new ReflectionMethod(AllianceController::class, 'invalidLabel'))
            ->invoke($this->controllerWithUser([]), $label, $kind);

        $this->assertSame($valid, $error === null);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'https' => ['https://xgp.test/logo.png', 'https://xgp.test/logo.png'];
        yield 'javascript' => ['javascript://x%0Aalert(1)', ''];
        yield 'quote breaks out' => ['http://xgp.test/"onerror=alert(1)', ''];
        yield 'not a url' => ['logo.png', ''];
    }

    /**
     * Regression (stored XSS): FILTER_VALIDATE_URL accepted javascript: and
     * quotes for the alliance image and homepage.
     */
    #[DataProvider('urlProvider')]
    public function testOnlyPlainHttpAddressesAreKept(string $url, string $expected): void
    {
        $this->assertSame($expected, (new ReflectionMethod(AllianceController::class, 'validUrl'))->invoke($this->controllerWithUser([]), $url));
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function controllerWithUser(array $user): AllianceController
    {
        $controller = new AllianceController(new FormatService(), new TimingService(new SettingsService()), new AllianceRankService());

        $property = (new ReflectionClass($controller))->getProperty('user');
        $property->setValue($controller, $user);

        return $controller;
    }
}
