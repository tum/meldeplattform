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
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /** Retry the whole fan-out a few times before giving up. */
    public int $tries = 3;

    /** Guard against a hung mail/HTTP endpoint holding a worker forever. */
    public int $timeout = 90;

    public function __construct(
        public readonly Topic $topic,
        public readonly string $title,
        public readonly Message $message,
        public readonly string $reportUrl,
    ) {}

    /**
     * Exponential-ish backoff between retries (seconds).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MessengerDispatcher $dispatcher): void
    {
        $dispatcher->sendNow($this->topic, $this->title, $this->message, $this->reportUrl);
    }

    /**
     * All retries exhausted: a responsible team was NOT notified about a
     * report. Log loudly so operators can follow up manually.
     */
    public function failed(Throwable $e): void
    {
        Log::critical('DispatchTopicNotifications: notification permanently failed after retries', [
            'topic_id' => $this->topic->id,
            'report_id' => $this->message->report_id,
            'error' => $e->getMessage(),
        ]);
    }
}
