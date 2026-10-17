<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services\Premium;

use App\Services\Premium\StripeSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Only Stripe can tell the game that a payment arrived: the webhook takes a
 * call only with a valid signature over the exact body, made with the
 * endpoint's secret within the last five minutes (no recorded call sent
 * again later).
 */
#[CoversClass(StripeSignature::class)]
class StripeSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const PAYLOAD = '{"id":"evt_1","type":"checkout.session.completed"}';

    private const NOW = 1_800_000_000;

    public function testAValidSignatureIsAccepted(): void
    {
        $signature = new StripeSignature();
        $header = $signature->sign(self::PAYLOAD, self::SECRET, self::NOW - 10);

        $this->assertTrue($signature->verify(self::PAYLOAD, $header, self::SECRET, self::NOW));
    }

    public function testTheHeaderHasStripesFormat(): void
    {
        $header = (new StripeSignature())->sign(self::PAYLOAD, self::SECRET, self::NOW);

        $this->assertSame('t=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET), $header);
    }

    public function testATamperedBodyIsRefused(): void
    {
        $signature = new StripeSignature();
        $header = $signature->sign(self::PAYLOAD, self::SECRET, self::NOW);

        $this->assertFalse($signature->verify(str_replace('evt_1', 'evt_2', self::PAYLOAD), $header, self::SECRET, self::NOW));
    }

    public function testAnotherSecretIsRefused(): void
    {
        $signature = new StripeSignature();
        $header = $signature->sign(self::PAYLOAD, 'whsec_other', self::NOW);

        $this->assertFalse($signature->verify(self::PAYLOAD, $header, self::SECRET, self::NOW));
    }

    public function testAChangedTimestampIsRefused(): void
    {
        $signature = new StripeSignature();
        $header = str_replace('t=' . self::NOW, 't=' . (self::NOW + 1), $signature->sign(self::PAYLOAD, self::SECRET, self::NOW));

        $this->assertFalse($signature->verify(self::PAYLOAD, $header, self::SECRET, self::NOW));
    }

    public function testAnOldSignatureIsRefused(): void
    {
        $signature = new StripeSignature();

        $this->assertTrue($signature->verify(self::PAYLOAD, $signature->sign(self::PAYLOAD, self::SECRET, self::NOW - 300), self::SECRET, self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, $signature->sign(self::PAYLOAD, self::SECRET, self::NOW - 301), self::SECRET, self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, $signature->sign(self::PAYLOAD, self::SECRET, self::NOW + 3600), self::SECRET, self::NOW));
    }

    public function testOneOfSeveralSignaturesMayMatch(): void
    {
        // while a secret is rolled, Stripe signs with the old and the new one
        $valid = (new StripeSignature())->sign(self::PAYLOAD, self::SECRET, self::NOW);
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',' . substr($valid, strpos($valid, 'v1=') ?: 0) . ',v0=abc';

        $this->assertTrue((new StripeSignature())->verify(self::PAYLOAD, $header, self::SECRET, self::NOW));
    }

    public function testMissingPartsAreRefused(): void
    {
        $signature = new StripeSignature();
        $header = $signature->sign(self::PAYLOAD, self::SECRET, self::NOW);

        $this->assertFalse($signature->verify(self::PAYLOAD, '', self::SECRET, self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, $header, '', self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, 't=' . self::NOW, self::SECRET, self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, 'v1=' . hash_hmac('sha256', '.' . self::PAYLOAD, self::SECRET), self::SECRET, self::NOW));
        $this->assertFalse($signature->verify(self::PAYLOAD, 'garbage', self::SECRET, self::NOW));
    }
}
