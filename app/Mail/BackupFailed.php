<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The nightly backup to the other server failed (xgp:backup:run).
 */
class BackupFailed extends Mailable
{
    use Queueable;

    public function __construct(public string $reason)
    {
    }

    public function envelope(): Envelope
    {
        $host = parse_url(is_string($url = config('app.url')) ? $url : '', PHP_URL_HOST);

        return new Envelope(subject: __('operator/operator.op_backup_failed_subject', ['host' => is_string($host) ? $host : '']));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.operator.backup_failed');
    }
}
