<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Summary sent to the global admins after `admins:prune` revoked dormant
 * administrator access, so a privilege change made by a scheduled job is
 * seen by a person. Carries UIDs and last-login dates only — no report data.
 *
 * Sent synchronously by PruneDormantAdmins — does not implement ShouldQueue.
 */
class DormantAdminsRevoked extends Mailable
{
    /**
     * @param list<array{uid: string, last_login: string|null, kind: string}> $revoked
     */
    public function __construct(
        public array $revoked,
        public int $windowDays,
        public string $usersUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('[SafeSignal] %d dormant administrator account(s) revoked', count($this->revoked)));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.dormant-admins-revoked');
    }
}
