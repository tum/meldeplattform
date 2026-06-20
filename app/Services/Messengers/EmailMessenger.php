<?php

namespace App\Services\Messengers;

use App\Mail\ReportNotification;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailMessenger implements Messenger
{
    public function __construct(private readonly string $target) {}

    public function send(string $title, Message $message, string $reportUrl): void
    {
        if (filter_var($this->target, FILTER_VALIDATE_EMAIL) === false) {
            // Permanent misconfiguration, not a transient error: log and skip
            // rather than throw, so we don't retry a send that can never work.
            Log::warning('EmailMessenger: invalid target email', ['target' => $this->target]);

            return;
        }

        // Do NOT swallow failures: a transient mail error must propagate so the
        // queued job retries it instead of silently dropping the notification.
        Mail::to($this->target)->send(new ReportNotification(
            subjectLine: $title,
            heading: $title,
            bodyHtml: $message->renderedBody(),
            linkUrl: $reportUrl,
        ));
    }
}
