<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The kinds of error that piled up, for the operators (xgp:errors:notify).
 */
class ErrorDigest extends Mailable
{
    use Queueable;

    /**
     * @param list<object> $groups
     */
    public function __construct(public array $groups)
    {
    }

    public function envelope(): Envelope
    {
        $host = parse_url(self::base(), PHP_URL_HOST);

        return new Envelope(subject: __('operator/operator.op_errors_mail_subject', ['count' => count($this->groups), 'host' => is_string($host) ? $host : '']));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.operator.errors', with: ['base' => self::base()]);
    }

    /**
     * The links point to APP_URL, never to the host a request named.
     */
    private static function base(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }
}
