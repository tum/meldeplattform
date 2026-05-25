<?php

namespace App\Services\Messengers;

/**
 * Typed view of a Matrix entry inside a topic's `contacts` JSON column.
 * Null when the JSON shape is incomplete or types are wrong — keeps the
 * dispatcher free of repeated is_string/is_array checks.
 */
readonly class MatrixContact
{
    public function __construct(
        public string $homeServer,
        public string $roomId,
        public string $accessToken,
    ) {}

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }
        $homeServer = is_string($raw['homeServer'] ?? null) ? trim($raw['homeServer']) : '';
        $roomId = is_string($raw['roomID'] ?? null) ? trim($raw['roomID']) : '';
        $token = is_string($raw['accessToken'] ?? null) ? trim($raw['accessToken']) : '';

        if ($homeServer === '' || $roomId === '') {
            return null;
        }

        return new self($homeServer, $roomId, $token);
    }
}
