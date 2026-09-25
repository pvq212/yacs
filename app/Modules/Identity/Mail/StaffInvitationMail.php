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
 * Staff 邀請信。token 放在 URL fragment，由前端以 POST 送出，不出現在伺服器 URL log。
 */
final class StaffInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $inviterName,
        private readonly string $token,
    ) {
        $this->onConnection('core')->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invitation.subject', ['workspace' => $this->workspaceName]));
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.staff-invitation',
            with: [
                'workspace' => $this->workspaceName,
                'inviter' => $this->inviterName,
                'url' => rtrim((string) config('app.url'), '/').'/auth/accept-invitation#token='.urlencode($this->token),
                'hours' => (int) config('yacs.staff.invitation_ttl_hours', 72),
            ],
        );
    }
}
