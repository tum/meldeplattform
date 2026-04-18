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
            Log::warning('EmailMessenger: invalid target email', ['target' => $this->target]);

            return;
        }

        try {
            Mail::to($this->target)->send(new ReportNotification(
                subjectLine: $title,
                heading: $title,
                bodyHtml: $message->renderedBody(),
                linkUrl: $reportUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('EmailMessenger: send failed', ['error' => $e->getMessage()]);
        }
    }
}
