<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fan a report notification out to a topic's configured messengers
 * (email / webhook) off the request path. Each messenger can take
 * up to ~10s on a slow endpoint; running them inline made every reporter
 * submission and admin reply wait on third-party I/O. With a real queue
 * worker this runs asynchronously; under the `sync` driver it still runs
 * inline, preserving the original behaviour for tests and single-process
 * deploys.
 */
class DispatchTopicNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Topic $topic,
        public readonly string $title,
        public readonly Message $message,
        public readonly string $reportUrl,
    ) {}

    public function handle(MessengerDispatcher $dispatcher): void
    {
        $dispatcher->sendNow($this->topic, $this->title, $this->message, $this->reportUrl);
    }
}
