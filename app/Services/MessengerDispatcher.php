<?php

namespace App\Services;

use App\Jobs\DispatchTopicNotifications;
use App\Models\Message;
use App\Models\Topic;
use App\Services\Messengers\EmailMessenger;
use App\Services\Messengers\Messenger;
use App\Services\Messengers\WebhookMessenger;

class MessengerDispatcher
{
    /** @return list<Messenger> */
    public function forTopic(Topic $topic): array
    {
        $contacts = TopicContacts::fromTopic($topic);
        /** @var list<Messenger> $messengers */
        $messengers = [];

        // `contacts.email.target` overrides the legacy `topics.email` column;
        // fall back to that column when no per-contacts email is configured
        // so older topics keep notifying their original mailbox.
        $emailTarget = $contacts->emailTarget;
        if ($emailTarget === null) {
            $topicEmail = is_string($topic->email) ? trim($topic->email) : '';
            $emailTarget = $topicEmail === '' ? null : $topicEmail;
        }
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
        foreach ($this->forTopic($topic) as $messenger) {
            $messenger->send($title, $message, $reportUrl);
        }
    }
}
