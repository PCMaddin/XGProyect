<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The link that confirms a new player's email address (EmailVerification).
 */
class VerifyEmail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $userName,
        public string $link,
        public int $deadline,
    ) {
    }

    public function envelope(): Envelope
    {
        $settings = app(SettingsService::class);

        return new Envelope(
            subject: __('account/verify.vf_mail_subject', ['game' => $settings->getString('game_name')]),
            from: new Address($settings->getString('admin_email'), $settings->getString('game_name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account.verify',
            with: ['gameName' => app(SettingsService::class)->getString('game_name')],
        );
    }
}
