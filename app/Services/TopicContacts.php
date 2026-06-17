<?php

namespace App\Services;

use App\Models\Topic;

/**
 * Typed view of a Topic's notification fan-out configuration. Hides the
 * raw `topics.contacts` JSON column behind nullable, validated fields so
 * MessengerDispatcher doesn't have to repeat the same is_array / is_string
 * checks at every call site.
 */
readonly class TopicContacts
{
    public function __construct(
        public ?string $emailTarget,
        public ?string $webhookTarget,
    ) {}

    public static function fromTopic(Topic $topic): self
    {
        /** @var array<string, mixed> $contacts */
        $contacts = is_array($topic->contacts) ? $topic->contacts : [];

        return new self(
            emailTarget: self::nestedString($contacts, 'email', 'target'),
            webhookTarget: self::nestedString($contacts, 'webhook', 'target'),
        );
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function nestedString(array $arr, string $outer, string $inner): ?string
    {
        $sub = $arr[$outer] ?? null;
        if (! is_array($sub)) {
            return null;
        }
        $value = $sub[$inner] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
