<?php

namespace App\Services\Messengers;

use App\Models\Message;
use Illuminate\Support\Facades\Http;

class WebhookMessenger implements Messenger
{
    public function __construct(private readonly string $target) {}

    public function send(string $title, Message $message, string $reportUrl): void
    {
        $body = (string) json_encode([
            'title' => $title,
            'message' => $message->renderedBody(),
            'url' => $reportUrl,
        ], JSON_THROW_ON_ERROR);

        $request = Http::timeout(10)->withBody($body, 'application/json');

        $secret = $this->signingSecret();
        if ($secret !== null) {
            // HMAC-SHA256 over the exact bytes we send so the receiver can
            // verify authenticity and integrity of the payload.
            $request = $request->withHeaders([
                'X-SafeSignal-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ]);
        }

        // Do NOT swallow failures: let transport/HTTP errors propagate so the
        // queued DispatchTopicNotifications job retries them. A silently dropped
        // webhook is an unhandled report notification.
        $request->post($this->target)->throw();
    }

    private function signingSecret(): ?string
    {
        $secret = config('meldeplattform.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
