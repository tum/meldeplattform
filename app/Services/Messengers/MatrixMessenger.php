<?php

namespace App\Services\Messengers;

use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MatrixMessenger implements Messenger
{
    public function __construct(
        private readonly string $homeServer,
        private readonly string $roomId,
        private readonly string $accessToken,
    ) {}

    public function send(string $title, Message $message, string $reportUrl): void
    {
        $escapedTitle = e($title);
        $escapedUrl = e($reportUrl);
        $body = $message->renderedBody();

        $payload = [
            'msgtype' => 'm.text',
            'format' => 'org.matrix.custom.html',
            'formatted_body' => "<h1>{$escapedTitle}</h1>{$body}<br><a href=\"{$escapedUrl}\">View Report</a>",
            'body' => "# {$title}\n\n{$message->content}\n\nView Report: {$reportUrl}",
        ];

        $url = sprintf(
            'https://%s/_matrix/client/r0/rooms/%s/send/m.room.message?access_token=%s',
            $this->homeServer,
            rawurlencode($this->roomId),
            rawurlencode($this->accessToken),
        );

        try {
            $resp = Http::timeout(10)->post($url, $payload);
            if ($resp->failed()) {
                Log::warning('MatrixMessenger: non-2xx response', ['status' => $resp->status()]);
            }
        } catch (\Throwable $e) {
            Log::error('MatrixMessenger: send failed', ['error' => $e->getMessage()]);
        }
    }
}
