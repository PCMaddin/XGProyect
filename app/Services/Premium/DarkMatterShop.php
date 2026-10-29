<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Services\Instances\Instance;
use App\Services\SettingsService;

/**
 * Whether dark matter can be bought here, and which packages.
 *
 * The rule:
 * - The shop is open only in a Pay2Win universe (option premium_mode) with
 *   a way to pay that the universe allows (option payment_methods) and the
 *   server has set up: Stripe (STRIPE_SECRET and STRIPE_WEBHOOK_SECRET),
 *   WAX (XGP_WAX_ACCOUNT) or the game's own token (XGP_TOKEN_*, paid to the
 *   same account). The "get dark matter" button then leads to it.
 * - Pay2Win without one: the button links to the option premium_url, if
 *   set (a shop of the operator's own).
 * - Play2Win: nothing can be bought and there is no button.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class DarkMatterShop
{
    public const PAGE = 'game.php?page=darkmatter';

    public const STRIPE = 'stripe';
    public const WAX = 'wax';
    public const TOKEN = 'token';
    public const METHODS = [self::STRIPE, self::WAX, self::TOKEN];

    public function __construct(private SettingsService $settings)
    {
    }

    public function isOpen(): bool
    {
        return $this->isPay2Win() && $this->methods() !== [] && $this->packages() !== [];
    }

    /**
     * The ways to pay here: allowed in the universe and set up on the server.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values(array_filter($this->allowedMethods(), fn (string $method): bool => $this->configured($method)));
    }

    /**
     * The ways to pay the universe allows (option payment_methods).
     *
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return self::parseMethods($this->option('payment_methods'));
    }

    /**
     * The known ways to pay in a list like "stripe, wax".
     *
     * @return list<string>
     */
    public static function parseMethods(string $list): array
    {
        $named = array_map(static fn (string $method): string => strtolower(trim($method)), explode(',', $list));

        return array_values(array_intersect(self::METHODS, $named));
    }

    public function configured(string $method): bool
    {
        if (!PaymentMode::allows($method)) {
            return false;
        }

        return match ($method) {
            self::STRIPE => $this->stripeConfigured(),
            self::WAX => $this->waxAccount() !== '',
            // tokens are paid to the shop's WAX account too
            self::TOKEN => $this->waxAccount() !== '' && TokenSpec::game() !== null,
            default => false,
        };
    }

    /**
     * Whether no way to pay here moves real money; the shop says so.
     */
    public function paysForTest(): bool
    {
        return PaymentMode::onlyTest($this->methods());
    }

    /**
     * The shop's WAX account, or empty if none (or not a valid name) is set.
     */
    public function waxAccount(): string
    {
        $account = self::config('premium.wax.account');

        return preg_match('/^[a-z1-5.]{1,12}$/', $account) === 1 ? $account : '';
    }

    public function isPay2Win(): bool
    {
        return $this->option('premium_mode') === Instance::PAY2WIN;
    }

    public function stripeConfigured(): bool
    {
        // without the webhook secret nothing bought would ever be credited
        return self::config('premium.stripe.secret') !== '' && self::config('premium.stripe.webhook_secret') !== '';
    }

    /**
     * Where the "get dark matter" button of the premium page leads; empty
     * for no button.
     */
    public function buyUrl(): string
    {
        if ($this->isOpen()) {
            return self::PAGE;
        }

        return $this->isPay2Win() ? $this->option('premium_url') : '';
    }

    /**
     * The valid packages of config/premium.php, by id.
     *
     * @return array<string, DarkMatterPackage>
     */
    public function packages(): array
    {
        $configured = config('premium.packages');
        $packages = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $package = is_array($entry) ? self::fromConfig($entry) : null;

            if ($package !== null) {
                $packages[$package->id] ??= $package;
            }
        }

        return $packages;
    }

    public function package(string $id): ?DarkMatterPackage
    {
        return $this->packages()[$id] ?? null;
    }

    /**
     * ISO currency code in lower case, as Stripe takes it.
     */
    public function currency(): string
    {
        $currency = strtolower(self::config('premium.currency'));

        return preg_match('/^[a-z]{3}$/', $currency) === 1 ? $currency : 'eur';
    }

    /**
     * A price in cents for people: "1,99 €" (the decimal mark of the language).
     */
    public function formatPrice(int $cents, string $currency): string
    {
        $mark = __('game/darkmatter.dm_decimal_mark');
        $symbols = ['eur' => '€', 'usd' => '$', 'gbp' => '£'];

        return number_format($cents / 100, 2, is_string($mark) ? $mark : '.', '') . ' ' . ($symbols[$currency] ?? strtoupper($currency));
    }

    /**
     * @param array<mixed> $entry
     */
    private static function fromConfig(array $entry): ?DarkMatterPackage
    {
        $id = $entry['id'] ?? null;
        $darkMatter = $entry['dark_matter'] ?? null;
        $price = $entry['price'] ?? null;

        if (!is_string($id) || preg_match('/^[a-z0-9_]{1,32}$/', $id) !== 1 || !is_int($darkMatter) || !is_int($price)) {
            return null;
        }

        return $darkMatter > 0 && $price > 0 ? new DarkMatterPackage($id, $darkMatter, $price) : null;
    }

    private function option(string $name): string
    {
        $option = $this->settings->all()[$name] ?? null;

        return $option === null ? '' : (string) $option->value;
    }

    private static function config(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }
}
