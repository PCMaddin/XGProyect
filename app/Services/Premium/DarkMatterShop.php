<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Services\Instances\Instance;
use App\Services\Instances\InstanceContext;
use App\Services\SettingsService;

/**
 * Whether dark matter can be bought here, and which packages.
 *
 * The rule:
 * - The shop is open only in a Pay2Win universe (option premium_mode) with
 *   both Stripe keys set (STRIPE_SECRET, STRIPE_WEBHOOK_SECRET); the "get
 *   dark matter" button then leads to it.
 * - Pay2Win without Stripe: the button links to the option premium_url, if
 *   set (a shop of the operator's own), as before.
 * - Play2Win: nothing can be bought and there is no button, except in a
 *   server without universes, where a premium_url that was set stays linked
 *   as before.
 */
class DarkMatterShop
{
    public const PAGE = 'game.php?page=darkmatter';

    public function __construct(
        private SettingsService $settings,
        private InstanceContext $context,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->isPay2Win() && $this->stripeConfigured() && $this->packages() !== [];
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

        if ($this->isPay2Win() || $this->context->current() === null) {
            return $this->option('premium_url');
        }

        return '';
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
