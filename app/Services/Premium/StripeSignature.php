<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * Checks the Stripe-Signature header of a webhook call, as Stripe documents
 * it: "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">" (several v1
 * while a secret is rolled), signed with the endpoint's secret (whsec_…).
 * A signature older than the tolerance is refused, so a recorded call
 * cannot be sent again later.
 */
final class StripeSignature
{
    public function verify(string $payload, string $header, string $secret, int $now, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        [$timestamp, $signatures] = self::parse($header);

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The header a call with this payload carries (for tests and tools).
     */
    public function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
