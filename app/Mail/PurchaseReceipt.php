<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Instances\InstanceContext;
use App\Services\Legal\LegalPages;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/**
 * The confirmation of a paid dark matter order (PurchaseReceipts).
 */
class PurchaseReceipt extends Mailable
{
    use Queueable;

    /** a test purchase: no real money moved */
    public bool $test = false;

    public function __construct(
        public int $orderId,
        public int $darkMatter,
        public string $price,
        public string $method,
        public int $paidAt,
        public int $waivedAt,
    ) {
    }

    public function markAsTest(): static
    {
        $this->test = true;

        return $this;
    }

    public function envelope(): Envelope
    {
        $settings = app(SettingsService::class);

        return new Envelope(
            subject: ($this->test ? '[TEST] ' : '') . __('emails/receipt.rc_subject', ['game' => $settings->getString('game_name'), 'order' => $this->orderId]),
            from: new Address($settings->getString('admin_email'), $settings->getString('game_name')),
        );
    }

    public function content(): Content
    {
        $written = app(LegalPages::class)->written();
        $links = [];

        foreach ([LegalPages::TERMS, LegalPages::WITHDRAWAL] as $page) {
            if (in_array($page, $written, true)) {
                $links[$page] = route('legal.show', ['page' => $page, 'lang' => $this->locale]);
            }
        }

        return new Content(
            markdown: 'emails.premium.receipt',
            with: [
                'gameName' => app(SettingsService::class)->getString('game_name'),
                'gameUrl' => app(InstanceContext::class)->link(URL::to('/')),
                'darkMatterText' => number_format($this->darkMatter, 0, ',', '.'),
                'links' => $links,
            ],
        );
    }
}
