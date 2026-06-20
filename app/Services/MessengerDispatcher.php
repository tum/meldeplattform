<?php

namespace App\Services;

use App\Jobs\DispatchTopicNotifications;
use App\Models\Message;
use App\Models\Topic;
use App\Services\Messengers\EmailMessenger;
use App\Services\Messengers\Messenger;
use App\Services\Messengers\WebhookMessenger;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MessengerDispatcher
{
    /**
     * Resolve the case-handler mailbox for a topic: `contacts.email.target`
     * overrides the legacy `topics.email` column; fall back to that column
     * when no per-contacts email is configured so older topics keep notifying
     * their original mailbox. Returns null when neither is set.
     */
    public function emailTarget(Topic $topic): ?string
    {
        $emailTarget = TopicContacts::fromTopic($topic)->emailTarget;
        if ($emailTarget === null) {
            $topicEmail = is_string($topic->email) ? trim($topic->email) : '';
            $emailTarget = $topicEmail === '' ? null : $topicEmail;
        }

        return $emailTarget;
    }

    /** @return list<Messenger> */
    public function forTopic(Topic $topic): array
    {
        $contacts = TopicContacts::fromTopic($topic);
        /** @var list<Messenger> $messengers */
        $messengers = [];

        $emailTarget = $this->emailTarget($topic);
        if ($emailTarget !== null) {
            $messengers[] = new EmailMessenger($emailTarget);
        }

        if ($contacts->webhookTarget !== null) {
            $messengers[] = new WebhookMessenger($contacts->webhookTarget);
        }

        return $messengers;
    }

    /**
     * Queue the notification fan-out so slow third-party endpoints never
     * block the reporter/admin request. Under the `sync` queue driver this
     * still runs inline.
     */
    public function dispatch(Topic $topic, string $title, Message $message, string $reportUrl): void
    {
        DispatchTopicNotifications::dispatch($topic, $title, $message, $reportUrl);
    }

    /**
     * Run the fan-out immediately. Called by DispatchTopicNotifications on
     * the queue; kept separate so the dispatch path stays trivially testable.
     */
    public function sendNow(Topic $topic, string $title, Message $message, string $reportUrl): void
    {
        $failures = [];

        // Attempt every channel even if one fails, so a broken webhook doesn't
        // suppress the email (and vice versa).
        foreach ($this->forTopic($topic) as $messenger) {
            try {
                $messenger->send($title, $message, $reportUrl);
            } catch (\Throwable $e) {
                $failures[] = $e;
                Log::error('MessengerDispatcher: messenger failed', [
                    'messenger' => $messenger::class,
                    'topic_id' => $topic->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Re-throw so the queued DispatchTopicNotifications job retries the
        // fan-out. Delivery is therefore at-least-once: a channel that already
        // succeeded may be re-notified on retry, which is acceptable for the
        // content-free "a report was opened/updated" pings.
        if ($failures !== []) {
            throw new RuntimeException(
                count($failures).' notification channel(s) failed for topic '.$topic->id,
                previous: $failures[0],
            );
        }
    }
}
