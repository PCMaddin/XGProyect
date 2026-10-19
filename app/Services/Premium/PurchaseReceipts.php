<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Mail\PurchaseReceipt;
use App\Models\DarkMatterOrder;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The confirmation of a paid order by mail, in the universe's language:
 * what was bought, for how much, and that the player asked for it at once
 * and gave up the right of withdrawal (to be confirmed on a durable medium).
 * Sent once the dark matter is credited; a mail server that cannot be
 * reached never undoes a payment.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class PurchaseReceipts
{
    public function __construct(private DarkMatterShop $shop, private SettingsService $settings)
    {
    }

    public function send(int $orderId): void
    {
        try {
            $order = DarkMatterOrder::find($orderId);
            $email = $order === null ? null : DB::table('users')->where('id', $order->user_id)->value('email');

            if ($order === null || !is_string($email) || $email === '') {
                return;
            }

            $receipt = new PurchaseReceipt(
                orderId: $order->id,
                darkMatter: $order->dark_matter,
                price: $this->shop->formatPrice($order->amount, $order->currency),
                method: $order->provider,
                paidAt: $order->paid_at ?? time(),
                waivedAt: $order->withdrawal_waived_at ?? $order->created_at,
            );

            if ($this->shop->paysForTest()) {
                $receipt->markAsTest();
            }

            Mail::to($email)->locale($this->settings->getString('lang'))->send($receipt);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
