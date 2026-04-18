<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Topic;
use App\Services\Messengers\EmailMessenger;
use App\Services\Messengers\MatrixMessenger;
use App\Services\Messengers\Messenger;
use App\Services\Messengers\WebhookMessenger;

class MessengerDispatcher
{
    /** @return list<Messenger> */
    public static function forTopic(Topic $topic): array
    {
        /** @var list<Messenger> $messengers */
        $messengers = [];

        /** @var array<string, mixed> $contacts */
        $contacts = $topic->contacts ?? [];

        $email = is_string($topic->email) ? trim($topic->email) : '';
        if ($email !== '' && ! isset($contacts['email'])) {
            $messengers[] = new EmailMessenger($email);
        }

        $emailCfg = is_array($contacts['email'] ?? null) ? $contacts['email'] : null;
        if ($emailCfg !== null && isset($emailCfg['target']) && is_string($emailCfg['target']) && $emailCfg['target'] !== '') {
            $messengers[] = new EmailMessenger($emailCfg['target']);
        }

        $matrixCfg = is_array($contacts['matrix'] ?? null) ? $contacts['matrix'] : null;
        if ($matrixCfg !== null
            && isset($matrixCfg['homeServer'], $matrixCfg['roomID'])
            && is_string($matrixCfg['homeServer'])
            && is_string($matrixCfg['roomID'])) {
            $messengers[] = new MatrixMessenger(
                $matrixCfg['homeServer'],
                $matrixCfg['roomID'],
                isset($matrixCfg['accessToken']) && is_string($matrixCfg['accessToken']) ? $matrixCfg['accessToken'] : '',
            );
        }

        $webhookCfg = is_array($contacts['webhook'] ?? null) ? $contacts['webhook'] : null;
        if ($webhookCfg !== null && isset($webhookCfg['target']) && is_string($webhookCfg['target']) && $webhookCfg['target'] !== '') {
            $messengers[] = new WebhookMessenger($webhookCfg['target']);
        }

        return $messengers;
    }

    public static function dispatch(Topic $topic, string $title, Message $message, string $reportUrl): void
    {
        foreach (self::forTopic($topic) as $messenger) {
            $messenger->send($title, $message, $reportUrl);
        }
    }
}
