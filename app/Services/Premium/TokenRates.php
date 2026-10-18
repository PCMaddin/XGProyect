<?php

declare(strict_types=1);

namespace App\Services\Premium;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;

/**
 * Prices in the game's own token: a fixed rate of dark matter per token
 * (config premium.token.dark_matter_per_token), and for information the
 * token's market price in WAX from its pool on the Alcor exchange.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TokenRates
{
    public function __construct(private Cache $cache)
    {
    }

    /**
     * The token units that buy this much dark matter, rounded up.
     */
    public function unitsFor(int $darkMatter, TokenSpec $token): int
    {
        return (int) ceil($darkMatter * $token->scale() / $this->darkMatterPerToken());
    }

    /**
     * The dark matter a prize is worth in token units, rounded down.
     */
    public function unitsForPrize(int $darkMatter, TokenSpec $token): int
    {
        return intdiv($darkMatter * $token->scale(), $this->darkMatterPerToken());
    }

    public function darkMatterPerToken(): int
    {
        $rate = config('premium.token.dark_matter_per_token');

        return is_int($rate) && $rate > 0 ? $rate : 100;
    }

    /**
     * What one token fetches in WAX on the market; null if unknown.
     */
    public function marketWax(TokenSpec $token): ?float
    {
        $key = 'token_market_' . strtolower($token->symbol . '-' . $token->contract);
        $cached = $this->cache->get($key);

        if (is_float($cached)) {
            return $cached > 0 ? $cached : null;
        }

        $url = config('premium.token.market_url');
        $price = 0.0;

        try {
            $response = Http::acceptJson()->timeout(10)->get(str_replace(':id', strtolower($token->symbol . '-' . $token->contract), is_string($url) ? $url : ''));
            $value = $response->successful() ? $response->json('system_price') : null;
            $price = is_numeric($value) ? (float) $value : 0.0;
        } catch (HttpClientException | TransferException) {
            $price = 0.0;
        }

        // an unknown price is asked again later, but not on every page
        $this->cache->put($key, $price, 300);

        return $price > 0 ? $price : null;
    }
}
