<?php

namespace App\Services\Messengers;

use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookMessenger implements Messenger
{
    public function __construct(private readonly string $target) {}

    public function send(string $title, Message $message, string $reportUrl): void
    {
        // Refuse non-HTTPS targets: the payload carries the report URL, whose
        // token grants access to the report. Sending it over cleartext HTTP
        // would expose that token to anyone on the network path.
        if (! Str::startsWith(Str::lower($this->target), 'https://')) {
            Log::warning('WebhookMessenger: refusing to send to a non-HTTPS target', [
                'target' => $this->target,
            ]);

            return;
        }

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
