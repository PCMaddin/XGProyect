<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Premium\StripeSignature;
use App\Services\Premium\StripeWebhookHandler;
use App\Services\Premium\UnknownUniverseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

/**
 * POST /stripe/webhook: Stripe reports payments here (no session, no CSRF
 * token, no login: the signature of the call is the proof). Answers 400 to
 * an unsigned or wrongly signed call and to an unknown universe, 200 to
 * everything else, so Stripe stops sending it.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class StripeWebhookController extends BaseController
{
    public function __invoke(Request $request, StripeSignature $signature, StripeWebhookHandler $handler): JsonResponse
    {
        $payload = $request->getContent();
        $secret = config('premium.stripe.webhook_secret');
        $tolerance = config('premium.stripe.tolerance');

        $valid = $signature->verify(
            $payload,
            (string) $request->header('Stripe-Signature', ''),
            is_string($secret) ? trim($secret) : '',
            time(),
            is_int($tolerance) ? $tolerance : 300
        );

        if (!$valid) {
            Log::warning('Stripe webhook: invalid signature', ['ip' => $request->ip()]);

            return new JsonResponse(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true);

        if (!is_array($event)) {
            return new JsonResponse(['error' => 'invalid payload'], 400);
        }

        try {
            $result = $handler->handle($event, time());
        } catch (UnknownUniverseException $exception) {
            Log::warning('Stripe webhook: ' . $exception->getMessage());

            return new JsonResponse(['error' => 'unknown universe'], 400);
        }

        return new JsonResponse(['received' => true, 'result' => $result]);
    }
}
