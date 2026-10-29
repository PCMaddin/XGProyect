<?php

declare(strict_types=1);

namespace App\Services\Premium;

/**
 * Real money or test payments: Stripe with a test key (sk_test_…), WAX on
 * the test network (premium.wax.network). With premium.test_mode (an
 * alpha) only the test ways are offered.
 */
final class PaymentMode
{
    public static function testOnly(): bool
    {
        return config('premium.test_mode') === true;
    }

    /**
     * Whether a way to pay moves no real money.
     */
    public static function isTest(string $method): bool
    {
        if ($method !== DarkMatterShop::STRIPE) {
            return config('premium.wax.network') === 'testnet';
        }

        $secret = config('premium.stripe.secret');

        return is_string($secret) && preg_match('/^(sk|rk)_test_/', $secret) === 1;
    }

    /**
     * Whether a way to pay may be offered: always, or in test mode only the
     * test ways.
     */
    public static function allows(string $method): bool
    {
        return !self::testOnly() || self::isTest($method);
    }

    /**
     * Whether none of these ways to pay moves real money.
     *
     * @param list<string> $methods
     */
    public static function onlyTest(array $methods): bool
    {
        return $methods !== [] && array_filter($methods, static fn (string $method): bool => !self::isTest($method)) === [];
    }
}
