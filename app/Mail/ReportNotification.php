<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notification-only mail: it tells a recipient that a report was opened or
 * updated and links to the secure, token-gated view. It deliberately carries
 * NO report content — the allegation text must never travel through mail
 * relays/logs. Recipients click through to the authenticated UI to read it.
 */
class ReportNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $heading,
        public string $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report',
            with: [
                'heading' => $this->heading,
                'linkUrl' => $this->linkUrl,
            ],
        );
    }
}
