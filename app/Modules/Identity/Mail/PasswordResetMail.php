<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 密碼重設信。連結含一次性 token（放在 URL fragment，不會送到伺服器 log 或 Referer）。
 */
final class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        private readonly string $token,
    ) {
        $this->onConnection('core')->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.password_reset.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.password-reset',
            with: [
                'name' => $this->recipientName,
                'url' => rtrim((string) config('app.url'), '/').'/auth/reset-password#token='.urlencode($this->token),
                'minutes' => (int) config('yacs.staff.password_reset_ttl_minutes', 30),
            ],
        );
    }
}
