<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\DarkMatterOrder;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Opens a Stripe Checkout session for an order, through Stripe's REST API
 * (POST /v1/checkout/sessions). The amount and currency come from the
 * order, never from the player. The order, the player and the universe
 * travel along as metadata: the webhook reads them back to credit the
 * dark matter in the right universe.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class StripeCheckout
{
    /** Stripe closes an unpaid session after this time (at least 30 minutes). */
    private const SESSION_LIFETIME = 3600;

    /**
     * @return array{id: string, url: string}
     *
     * @throws StripeException
     */
    public function createSession(
        DarkMatterOrder $order,
        string $productName,
        string $successUrl,
        string $cancelUrl,
        string $universe,
    ): array {
        $metadata = [
            'order_id' => (string) $order->id,
            'user_id' => (string) $order->user_id,
            'universe' => $universe,
        ];

        $parameters = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $order->currency,
                    'unit_amount' => $order->amount,
                    'product_data' => ['name' => $productName],
                ],
            ]],
            'client_reference_id' => (string) $order->id,
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => time() + self::SESSION_LIFETIME,
        ];

        if ((bool) config('premium.stripe.automatic_tax')) {
            // Stripe Tax works the VAT of the buyer's country out of the
            // price: the player pays what the shop shows, the books get the
            // tax and the country with the payment (webhook)
            $parameters['automatic_tax'] = ['enabled' => 'true'];
            $parameters['line_items'][0]['price_data']['tax_behavior'] = 'inclusive';
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth(self::secret(), '')
                ->withHeaders(['Idempotency-Key' => 'xgp-dm-' . ($universe === '' ? '' : $universe . '-') . $order->id])
                ->timeout(15)
                ->post(self::apiUrl() . '/checkout/sessions', $parameters);
        } catch (HttpClientException | TransferException $exception) {
            Log::warning('Stripe checkout: Stripe could not be reached', ['order' => $order->id, 'error' => $exception->getMessage()]);

            throw new StripeException('Stripe could not be reached', 0, $exception);
        }

        return self::session($response, $order->id);
    }

    /**
     * @return array{id: string, url: string}
     *
     * @throws StripeException
     */
    private static function session(Response $response, int $orderId): array
    {
        $session = $response->json();
        $id = is_array($session) ? ($session['id'] ?? null) : null;
        $url = is_array($session) ? ($session['url'] ?? null) : null;

        if (!$response->successful() || !is_string($id) || !is_string($url) || !str_starts_with($url, 'https://')) {
            // type and code only: the message of a wrong key quotes part of it
            $error = is_array($session) && is_array($session['error'] ?? null) ? $session['error'] : [];
            Log::warning('Stripe checkout: session refused', [
                'order' => $orderId,
                'status' => $response->status(),
                'type' => is_string($error['type'] ?? null) ? $error['type'] : '',
                'code' => is_string($error['code'] ?? null) ? $error['code'] : '',
            ]);

            throw new StripeException('Stripe refused the checkout session (HTTP ' . $response->status() . ')');
        }

        return ['id' => $id, 'url' => $url];
    }

    private static function secret(): string
    {
        $secret = config('premium.stripe.secret');

        return is_string($secret) ? trim($secret) : '';
    }

    private static function apiUrl(): string
    {
        $url = config('premium.stripe.api_url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://api.stripe.com/v1', '/');
    }
}
