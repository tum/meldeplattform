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

        // Notification-only: send a content-free event (id + link), never the
        // report body — the webhook target is operator-configured and
        // potentially external, so the allegation text must not be sent.
        $body = (string) json_encode([
            'title' => $title,
            'report_id' => $message->report_id,
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
