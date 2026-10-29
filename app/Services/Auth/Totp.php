<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238) as authenticator apps make them:
 * HMAC-SHA1 over 30-second steps, six digits, the secret in base32.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** a code of the step before or after is accepted too (clocks drift) */
    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new secret: 160 random bits in base32 (32 characters).
     */
    public function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The code of a time step.
     */
    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::unbase32($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24 | ord($hash[$offset + 1]) << 16 | ord($hash[$offset + 2]) << 8 | ord($hash[$offset + 3]))
            % 10 ** self::DIGITS;

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step the code belongs to, or null. A step at or before
     * $usedStep is refused: a code counts once.
     */
    public function verify(string $secret, string $code, int $time, ?int $usedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $now = $this->step($time);

        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (($usedStep === null || $step > $usedStep) && hash_equals($this->code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * What an authenticator app scans: otpauth://totp/Issuer:account?…
     */
    public function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function unbase32(string $encoded): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
            $value = strpos(self::BASE32, $char);
            $bits .= $value === false ? '' : str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
