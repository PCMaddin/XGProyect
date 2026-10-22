<?php

declare(strict_types=1);

namespace Tests\Unit\App\Http\Middleware;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * The player's address is the one that calls, unless the operator named
 * the proxies in front of the game (XGP_TRUSTED_PROXIES): a forged
 * X-Forwarded-For header changes nothing for the rate limits, the sign-in
 * log and the cheat detection.
 */
#[CoversClass(TrustProxies::class)]
class TrustProxiesTest extends TestCase
{
    public function testAForgedHeaderDoesNotChangeTheAddress(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('10.0.0.5', $this->seenFrom('10.0.0.5', '203.0.113.9'));
    }

    public function testANamedProxyIsTrusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        $this->assertSame('203.0.113.9', $this->seenFrom('10.0.0.5', '203.0.113.9'));
        $this->assertSame('10.0.0.6', $this->seenFrom('10.0.0.6', '203.0.113.9'));
    }

    private function seenFrom(string $remote, string $forwarded): string
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $forwarded]);

        $ip = (new TrustProxies())->handle($request, static fn (Request $seen): string => (string) $seen->ip());
        $this->assertIsString($ip);

        return $ip;
    }
}
