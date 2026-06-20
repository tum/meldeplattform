<?php

namespace App\Services\Messengers;

use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookMessenger implements Messenger
{
    public function __construct(private readonly string $target) {}

    public function send(string $title, Message $message, string $reportUrl): void
    {
        try {
            // Notification-only: send a content-free event (id + link), never
            // the report body — the webhook target is operator-configured and
            // potentially external, so the allegation text must not be sent.
            Http::timeout(10)->post($this->target, [
                'title' => $title,
                'report_id' => $message->report_id,
                'url' => $reportUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('WebhookMessenger: send failed', ['error' => $e->getMessage()]);
        }
    }
}
