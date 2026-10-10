<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Password reset link. Sent synchronously (not queued) so it works on shared
 * hosting without a queue worker, matching LowStockAlert/StockSummary.
 */
class PasswordResetLink extends Mailable
{
    public function __construct(
        public string $resetUrl,
        public int $expiresMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your '.config('app.name').' password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'expiresMinutes' => $this->expiresMinutes,
            ],
        );
    }
}
