<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification-only digest reminding a topic's case handlers about reports
 * approaching or past an EU Directive / HinSchG §17 deadline (7-day
 * acknowledgement, 3-month feedback).
 *
 * Like ReportNotification it carries NO report content — only report IDs and
 * the relevant deadline, so nothing confidential travels through mail relays.
 * Handlers click through to the secure dashboard to act.
 *
 * Sent synchronously by SendDeadlineReminders — does not implement ShouldQueue.
 */
class DeadlineReminder extends Mailable
{

    /**
     * @param list<array{id: int, type: string, due: string, overdue: bool}> $items
     */
    public function __construct(
        public string $subjectLine,
        public string $topicName,
        public string $dashboardUrl,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deadline-reminder',
            with: [
                'topicName' => $this->topicName,
                'dashboardUrl' => $this->dashboardUrl,
                'items' => $this->items,
            ],
        );
    }
}
