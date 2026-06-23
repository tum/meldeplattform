<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads the list of available queue names from OTRS so the topic editor can
 * offer them as a dropdown instead of free text (a mistyped queue name makes
 * every TicketCreate fail, and the job then retries forever).
 *
 * The stock GenericTicketConnector has no queue-listing operation, so this hits
 * a SEPARATE, operator-provided endpoint (`MELDE_OTRS_QUEUE_LIST_URL`) —
 * typically a custom GenericInterface operation on the same web service. The
 * response is parsed leniently (a JSON list of names, or a list/object of
 * entries carrying a Name/Queue key) so it adapts to whatever shape that
 * operation returns. When the URL is unset the feature is simply off and the
 * editor falls back to a free-text field.
 */
class OtrsQueues
{
    public function __construct(
        private readonly string $url,
        private readonly string $userLogin,
        private readonly string $password,
        private readonly int $timeout,
    ) {}

    /**
     * Build from the global `meldeplattform.otrs` config. Returns null (feature
     * off) unless a queue-list URL is set and the shared credentials exist.
     */
    public static function fromConfig(): ?self
    {
        $cfg = config('meldeplattform.otrs');
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($cfg) ? $cfg : [];

        $url = self::configString($cfg, 'queue_list_url');
        $userLogin = self::configString($cfg, 'user_login');
        $password = self::configString($cfg, 'password');

        if ($url === null || $userLogin === null || $password === null) {
            return null;
        }

        return new self($url, $userLogin, $password, self::configInt($cfg, 'timeout', 10));
    }

    /**
     * Fetch queue names, de-duplicated and naturally sorted. Throws on
     * transport / OTRS application error so the caller can decide how to
     * degrade (the editor falls back to free text).
     *
     * @return list<string>
     */
    public function fetch(): array
    {
        // Credentials travel as query params; HTTPS-only, like the other OTRS
        // calls.
        if (! Str::startsWith(Str::lower($this->url), 'https://')) {
            Log::error('OtrsQueues: refusing to query a non-HTTPS endpoint', ['url' => $this->url]);

            return [];
        }

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->get($this->url, ['UserLogin' => $this->userLogin, 'Password' => $this->password])
            ->throw();

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        if (isset($data['Error'])) {
            $error = is_array($data['Error']) ? $data['Error'] : [];
            $code = isset($error['ErrorCode']) && is_scalar($error['ErrorCode']) ? (string) $error['ErrorCode'] : '?';
            $msg = isset($error['ErrorMessage']) && is_scalar($error['ErrorMessage']) ? (string) $error['ErrorMessage'] : 'unknown';

            throw new RuntimeException("OTRS GenericInterface error {$code}: {$msg}");
        }

        return $this->parse($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function parse(array $data): array
    {
        // The queue array may be the top-level response or nested under a
        // common key, depending on the operator's custom operation.
        $list = $data;
        foreach (['Queue', 'Queues', 'QueueList'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $list = $data[$key];
                break;
            }
        }

        /** @var array<string, true> $names */
        $names = [];
        foreach ($list as $entry) {
            $name = match (true) {
                is_string($entry) => trim($entry),
                is_array($entry) => self::firstString($entry, ['Name', 'Queue', 'QueueName']),
                default => '',
            };
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        $result = array_keys($names);
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    /**
     * @param array<int|string, mixed> $arr
     * @param list<string> $keys
     */
    private static function firstString(array $arr, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $arr[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function configString(array $cfg, string $key): ?string
    {
        $value = $cfg[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function configInt(array $cfg, string $key, int $default): int
    {
        $value = $cfg[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
