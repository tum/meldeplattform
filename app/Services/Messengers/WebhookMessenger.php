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
            Http::timeout(10)->post($this->target, [
                'title' => $title,
                'message' => $message->renderedBody(),
                'url' => $reportUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('WebhookMessenger: send failed', ['error' => $e->getMessage()]);
        }
    }
}
