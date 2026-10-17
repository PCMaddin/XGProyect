<?php

declare(strict_types=1);

namespace App\Services\Premium;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;

/**
 * What a package costs in WAX: its price in the shop's currency at the
 * current rate (read from config premium.wax.rate_url, kept a few minutes),
 * plus the markup, rounded up to whole hundredths of a WAX.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class WaxRates
{
    public function __construct(private Cache $cache)
    {
    }

    /**
     * The price in WAX units (1 WAX = 100 000 000) of an amount in cents.
     *
     * @throws WaxException
     */
    public function units(int $cents, string $currency): int
    {
        $markup = config('premium.wax.markup');
        $price = $cents / 100 * (1 + (is_numeric($markup) ? max(0.0, (float) $markup) : 0.0) / 100);
        $hundredths = (int) ceil(round($price / $this->rate($currency) * 100, 6));

        return $hundredths * (WaxChain::UNITS / 100);
    }

    /**
     * "467.25 WAX" (the decimal mark of the language).
     */
    public static function format(int $units, string $mark = '.'): string
    {
        return number_format($units / WaxChain::UNITS, 2, $mark, '') . ' ' . WaxChain::SYMBOL;
    }

    /**
     * What one WAX is worth in the currency.
     *
     * @throws WaxException
     */
    public function rate(string $currency): float
    {
        $cached = $this->cache->get('wax_rate_' . $currency);

        if (is_float($cached) && $cached > 0) {
            return $cached;
        }

        $rate = $this->fetch($currency);
        $seconds = config('premium.wax.rate_cache');
        $this->cache->put('wax_rate_' . $currency, $rate, is_int($seconds) ? $seconds : 300);

        return $rate;
    }

    /**
     * @throws WaxException
     */
    private function fetch(string $currency): float
    {
        $url = config('premium.wax.rate_url');

        try {
            $response = Http::acceptJson()->timeout(10)->get(str_replace(':currency', $currency, is_string($url) ? $url : ''));
        } catch (HttpClientException | TransferException $exception) {
            throw new WaxException('The WAX rate could not be read: ' . $exception->getMessage(), 0, $exception);
        }

        $rate = $response->json('wax.' . $currency);

        if (!$response->successful() || !is_numeric($rate) || (float) $rate <= 0) {
            throw new WaxException('No WAX rate for ' . $currency . ' (HTTP ' . $response->status() . ')');
        }

        return (float) $rate;
    }
}
