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
        // Refuse non-HTTPS targets. The payload is content-free, and the URL it
        // carries is the auth-gated admin route (no token — an earlier comment
        // here claimed otherwise), so the leak is the topic name and report id
        // rather than report access. Still not something to hand to anyone on
        // the network path, and HTTPS keeps the signature header meaningful.
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

        // withoutRedirecting(): the https check above only constrains the first
        // hop. Guzzle follows redirects by default with protocols http+https and
        // strict=false, so a target could answer `307 Location: http://127.0.0.1:…`
        // and have us re-POST the body — method and payload intact — to an
        // internal cleartext service. The target is topic-admin-configurable and
        // `url:https` validation accepts any host, so this is the SSRF hop.
        // A webhook receiver has no legitimate reason to redirect.
        $request = Http::timeout(10)
            ->withoutRedirecting()
            ->withBody($body, 'application/json');

        $secret = $this->signingSecret();
        if ($secret !== null) {
            // HMAC-SHA256 over the exact bytes we send so the receiver can
            // verify authenticity and integrity of the payload.
            $request = $request->withHeaders([
                'X-SafeSignal-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ]);
        }

        $response = $request->post($this->target);

        // A 3xx is no longer followed, so nothing was delivered. throw() only
        // fires on 4xx/5xx, which would let a redirect masquerade as success —
        // surface it as the misconfigured target it is, the same way a
        // non-HTTPS target is handled above.
        if ($response->redirect()) {
            Log::warning('WebhookMessenger: target responded with a redirect, which is not followed; nothing was delivered', [
                'target' => $this->target,
                'status' => $response->status(),
            ]);

            return;
        }

        // Do NOT swallow failures: let transport/HTTP errors propagate so the
        // queued DispatchTopicNotifications job retries them. A silently dropped
        // webhook is an unhandled report notification.
        $response->throw();
    }

    private function signingSecret(): ?string
    {
        $secret = config('meldeplattform.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
