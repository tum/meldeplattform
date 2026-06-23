<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inbound counterpart to OtrsMessenger: reads agent answers back OUT of an
 * OTRS/Znuny ticket through the GenericInterface "Ticket Connector" REST web
 * service (TicketGet) so they can be mirrored into the report and shown to the
 * reporter in the platform.
 *
 * Which articles count as "an answer for the reporter": an OTRS agent makes an
 * article customer-visible (IsVisibleForCustomer=1). That single rule both
 * selects genuine answers AND excludes the internal articles OtrsMessenger
 * itself pushes (always IsVisibleForCustomer=0), so our own writes are never
 * re-imported. Customer- and system-authored articles are skipped too.
 *
 * Idempotency: OTRS ArticleIDs increase monotonically, so each report stores
 * the id of the last answer it imported (`reports.otrs_last_article_id`) and we
 * only return articles newer than that. The scheduled poll is therefore safe to
 * run repeatedly — at-least-once delivery never duplicates a message.
 */
class OtrsReplyImporter
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userLogin,
        private readonly string $password,
        private readonly int $timeout,
    ) {}

    /**
     * Build from the global `meldeplattform.otrs` config. Returns null (so the
     * poll command no-ops) unless inbound is explicitly enabled AND the shared
     * connection is fully configured — the same connection OtrsMessenger uses.
     */
    public static function fromConfig(): ?self
    {
        $cfg = config('meldeplattform.otrs');
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($cfg) ? $cfg : [];

        if (($cfg['inbound_enabled'] ?? false) !== true) {
            return null;
        }

        $baseUrl = self::configString($cfg, 'base_url');
        $userLogin = self::configString($cfg, 'user_login');
        $password = self::configString($cfg, 'password');

        if ($baseUrl === null || $userLogin === null || $password === null) {
            Log::warning('OtrsReplyImporter: inbound is enabled but the MELDE_OTRS_* connection is not fully configured; skipping.');

            return null;
        }

        return new self(
            baseUrl: $baseUrl,
            userLogin: $userLogin,
            password: $password,
            timeout: self::configInt($cfg, 'timeout', 10),
        );
    }

    /**
     * Fetch the agent answers on this report's ticket that have not been
     * imported yet, oldest first. Empty when the report has no ticket, the
     * endpoint is not HTTPS, or there is nothing new.
     *
     * Transport and OTRS application errors are thrown so the caller can log
     * and move on to the next report (one unreachable ticket must not abort the
     * whole poll).
     *
     * @return list<array{id: string, body: string}>
     */
    public function newAnswers(Report $report): array
    {
        $ticketId = $report->otrs_ticket_id;
        if ($ticketId === null || $ticketId === '') {
            return [];
        }

        // Credentials would otherwise cross the network in cleartext (TicketGet
        // takes them as query params), and the article body we read back is the
        // full report content. HTTPS-only, exactly like the outbound channel.
        if (! Str::startsWith(Str::lower($this->baseUrl), 'https://')) {
            Log::error('OtrsReplyImporter: refusing to poll a non-HTTPS OTRS endpoint', [
                'base_url' => $this->baseUrl,
            ]);

            return [];
        }

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->get($this->endpoint($ticketId), [
                'UserLogin' => $this->userLogin,
                'Password' => $this->password,
                'AllArticles' => 1,
                'Attachments' => 0,
                'DynamicFields' => 0,
            ])
            ->throw();

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        // OTRS signals operational failures (auth, unknown ticket) with HTTP 200
        // and an `Error` object, so ->throw() alone is not enough.
        if (isset($data['Error'])) {
            $error = is_array($data['Error']) ? $data['Error'] : [];
            $code = isset($error['ErrorCode']) && is_scalar($error['ErrorCode']) ? (string) $error['ErrorCode'] : '?';
            $msg = isset($error['ErrorMessage']) && is_scalar($error['ErrorMessage']) ? (string) $error['ErrorMessage'] : 'unknown';

            throw new RuntimeException("OTRS GenericInterface error {$code}: {$msg}");
        }

        return $this->extractNewAnswers($data, (int) ($report->otrs_last_article_id ?? 0));
    }

    /**
     * Reduce a TicketGet response to the customer-visible agent articles newer
     * than $sinceArticleId, oldest first.
     *
     * @param array<string, mixed> $data
     * @return list<array{id: string, body: string}>
     */
    private function extractNewAnswers(array $data, int $sinceArticleId): array
    {
        // TicketGet returns `Ticket` as a single-element list of ticket objects.
        $ticket = $data['Ticket'] ?? null;
        $ticket = is_array($ticket) ? ($ticket[0] ?? $ticket) : [];
        $articles = is_array($ticket) ? ($ticket['Article'] ?? []) : [];
        if (! is_array($articles)) {
            return [];
        }

        $answers = [];
        foreach ($articles as $article) {
            if (! is_array($article)) {
                continue;
            }

            $articleId = isset($article['ArticleID']) && is_scalar($article['ArticleID'])
                ? (int) $article['ArticleID']
                : 0;
            if ($articleId <= $sinceArticleId) {
                continue; // already imported (or unidentifiable)
            }

            // Only an agent's answer that was explicitly made customer-visible.
            // Our own pushes are agent-authored but always invisible, so they
            // fall out here — no echo.
            if (self::str($article, 'SenderType') !== 'agent') {
                continue;
            }
            if (! self::isVisibleForCustomer($article)) {
                continue;
            }

            $answers[] = [
                'id' => (string) $articleId,
                'body' => $this->articleText($article),
                '_sort' => $articleId,
            ];
        }

        // Oldest first so messages thread in the order the agent wrote them and
        // otrs_last_article_id advances monotonically as each is imported.
        usort($answers, fn (array $a, array $b): int => $a['_sort'] <=> $b['_sort']);

        return array_map(
            static fn (array $a): array => ['id' => $a['id'], 'body' => $a['body']],
            $answers,
        );
    }

    /**
     * Plain-text article body. OTRS may return HTML (ContentType text/html);
     * reduce it to text since messages are rendered from markdown/plain text.
     *
     * @param array<string, mixed> $article
     */
    private function articleText(array $article): string
    {
        $body = self::str($article, 'Body') ?? '';
        $contentType = Str::lower(self::str($article, 'ContentType') ?? '');

        if (str_contains($contentType, 'html')) {
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim($body);
    }

    private function endpoint(string $ticketId): string
    {
        return rtrim($this->baseUrl, '/').'/Ticket/'.rawurlencode($ticketId);
    }

    /**
     * OTRS may return IsVisibleForCustomer as int or string ("1"/"0").
     *
     * @param array<string, mixed> $article
     */
    private static function isVisibleForCustomer(array $article): bool
    {
        $value = $article['IsVisibleForCustomer'] ?? null;

        return is_scalar($value) && (int) $value === 1;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function str(array $arr, string $key): ?string
    {
        $value = $arr[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
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
