<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Models\DarkMatterOrder;
use App\Services\Instances\InstanceContext;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * What a verified Stripe event does to the orders:
 * - checkout.session.completed (paid) and
 *   checkout.session.async_payment_succeeded: credit the order once;
 * - checkout.session.expired and checkout.session.async_payment_failed:
 *   the order expires;
 * - charge.refunded (in full): the order is refunded and the dark matter
 *   the player still has is taken back (never below zero).
 *
 * The universe of the order comes with the session's metadata; its
 * database is switched to before any order is read.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class StripeWebhookHandler
{
    public function __construct(
        private DarkMatterOrders $orders,
        private InstanceRegistry $registry,
        private InstanceContext $context,
        private PurchaseReceipts $receipts,
    ) {
    }

    /**
     * @param array<mixed> $event the decoded event
     *
     * @return string what was done (for the log and the answer to Stripe)
     *
     * @throws UnknownUniverseException
     */
    public function handle(array $event, int $now): string
    {
        $data = self::arr($event['data'] ?? null);
        $object = self::arr($data['object'] ?? null);

        return match (self::str($event['type'] ?? null)) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->inUniverse($object, fn (): string => $this->paid($object, $now)),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->inUniverse($object, fn (): string => $this->expired($object)),
            'charge.refunded' => $this->refunded($object, $now),
            // a chargeback: the money is gone, the dark matter must go too
            'charge.dispute.created' => $this->reversed(self::str($object['payment_intent'] ?? null), self::arr($object['metadata'] ?? null), $now, DarkMatterOrder::DISPUTED),
            default => 'ignored',
        };
    }

    /**
     * @param array<mixed> $session
     */
    private function paid(array $session, int $now): string
    {
        // card payments are paid at once; others (bank debits) arrive later
        // with checkout.session.async_payment_succeeded
        if (self::str($session['payment_status'] ?? null) !== 'paid') {
            return 'not paid yet';
        }

        $orderId = self::orderId($session);
        $sessionId = self::str($session['id'] ?? null);

        try {
            $credited = $this->orders->credit(
                $orderId,
                $sessionId,
                self::int($session['amount_total'] ?? null),
                self::str($session['currency'] ?? null),
                self::str($session['payment_intent'] ?? null),
                $now
            );
        } catch (OrderNotCreditedException $exception) {
            Log::error('Stripe webhook: paid order ' . $orderId . ' could not be credited: ' . $exception->getMessage());

            return 'not credited';
        }

        if ($credited) {
            Log::info('Stripe webhook: order ' . $orderId . ' paid and credited', ['universe' => $this->universe()]);
            $this->receipts->send($orderId);

            return 'credited';
        }

        $order = DarkMatterOrder::find($orderId);

        if ($order !== null && $order->status === DarkMatterOrder::PAID && $order->provider_session === $sessionId) {
            return 'already credited';
        }

        Log::warning('Stripe webhook: session ' . $sessionId . ' does not match a pending order ' . $orderId, ['universe' => $this->universe()]);

        return 'not credited';
    }

    /**
     * @param array<mixed> $session
     */
    private function expired(array $session): string
    {
        return $this->orders->expire(self::orderId($session), self::str($session['id'] ?? null)) ? 'expired' : 'ignored';
    }

    /**
     * @param array<mixed> $charge
     */
    private function refunded(array $charge, int $now): string
    {
        // a partial refund leaves the dark matter (the admin decides)
        if (($charge['refunded'] ?? false) !== true) {
            return 'partial refund ignored';
        }

        return $this->reversed(self::str($charge['payment_intent'] ?? null), self::arr($charge['metadata'] ?? null), $now, DarkMatterOrder::REFUNDED);
    }

    /**
     * Takes a payment back (refund or chargeback) in the universe that knows it.
     *
     * @param array<mixed> $metadata
     *
     * @throws UnknownUniverseException
     */
    private function reversed(string $paymentId, array $metadata, int $now, string $status): string
    {
        $reverse = fn (): string => $this->orders->refund($paymentId, $now, $status) ? $status : 'ignored';

        // the charge may not carry the session's metadata: then the
        // universe is the one that knows the payment
        if (self::str($metadata['universe'] ?? null) !== '' || !$this->registry->enabled()) {
            return $this->inUniverse(['metadata' => $metadata], $reverse);
        }

        foreach ($this->registry->withDatabase() as $instance) {
            if ($this->context->runIn($instance, $reverse) === $status) {
                Log::warning('Stripe webhook: payment ' . $status, ['universe' => $instance->slug, 'payment' => $paymentId]);

                return $status;
            }
        }

        return 'ignored';
    }

    /**
     * @param array<mixed> $object a session or charge with metadata.universe
     * @param callable(): string $work
     *
     * @throws UnknownUniverseException
     */
    private function inUniverse(array $object, callable $work): string
    {
        $slug = self::str(self::arr($object['metadata'] ?? null)['universe'] ?? null);

        if ($slug === '') {
            return $work();
        }

        $instance = $this->registry->find($slug);

        if ($instance === null) {
            throw new UnknownUniverseException('Unknown universe ' . $slug);
        }

        // its database is in the archive: nothing to credit or take back here;
        // reported, so the operators see it (and Stripe stops sending it again)
        if (!$instance->hasDatabase()) {
            report(new RuntimeException('Stripe webhook for the archived universe ' . $slug . ': handle it by hand (xgp:instance:unarchive).'));

            return 'universe archived';
        }

        return $this->context->runIn($instance, $work);
    }

    private function universe(): string
    {
        return $this->context->current()->slug ?? '';
    }

    /**
     * @param array<mixed> $session
     */
    private static function orderId(array $session): int
    {
        $metadata = self::arr($session['metadata'] ?? null);
        $orderId = self::str($metadata['order_id'] ?? null);

        if ($orderId === '') {
            $orderId = self::str($session['client_reference_id'] ?? null);
        }

        return ctype_digit($orderId) ? (int) $orderId : 0;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : -1;
    }
}
