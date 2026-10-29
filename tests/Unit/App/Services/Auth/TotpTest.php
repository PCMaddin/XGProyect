<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Auth;

use App\Services\Auth\Totp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The codes match the test vectors of RFC 6238 (SHA-1; the last six of the
 * eight digits), so every authenticator app shows the same.
 */
#[CoversClass(Totp::class)]
class TotpTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function vectors(): iterable
    {
        yield '59' => [59, '287082'];
        yield '1111111109' => [1111111109, '081804'];
        yield '1111111111' => [1111111111, '050471'];
        yield '1234567890' => [1234567890, '005924'];
        yield '2000000000' => [2000000000, '279037'];
        yield '20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('vectors')]
    public function testTheCodesOfRfc6238(int $time, string $code): void
    {
        $totp = new Totp();

        $this->assertSame($code, $totp->code(Totp::base32('12345678901234567890'), $totp->step($time)));
    }

    public function testACodeIsValidForOneStepEachSideAndOnlyOnce(): void
    {
        $totp = new Totp();
        $secret = $totp->secret();
        $now = 1_900_000_000;
        $step = $totp->step($now);

        $this->assertSame(32, strlen($secret));
        $this->assertSame($step, $totp->verify($secret, $totp->code($secret, $step), $now));
        $this->assertSame($step - 1, $totp->verify($secret, $totp->code($secret, $step - 1), $now));
        $this->assertSame($step + 1, $totp->verify($secret, ' ' . $totp->code($secret, $step + 1) . ' ', $now));
        $this->assertNull($totp->verify($secret, $totp->code($secret, $step - 2), $now));
        $this->assertNull($totp->verify($secret, $totp->code($secret, $step + 2), $now));
        // used already
        $this->assertNull($totp->verify($secret, $totp->code($secret, $step), $now, $step));
        $this->assertNull($totp->verify($secret, 'abcdef', $now));
        $this->assertNull($totp->verify($secret, '12345', $now));
    }

    public function testTheUriNamesIssuerAndAccount(): void
    {
        $this->assertSame(
            'otpauth://totp/XG%20Proyect%20%28Nov%29:a%40b.c?secret=ABC&issuer=XG%20Proyect%20%28Nov%29&digits=6&period=30',
            (new Totp())->uri('XG Proyect (Nov)', 'a@b.c', 'ABC')
        );
    }
}
