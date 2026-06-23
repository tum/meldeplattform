<?php

namespace App\Services\Messengers;

use App\Models\Message;
use App\Models\Report;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pushes report notifications into an OTRS/Znuny ticket system through the
 * GenericInterface "Ticket Connector" REST web service (TicketCreate /
 * TicketUpdate).
 *
 * UNLIKE EmailMessenger and WebhookMessenger — which are deliberately
 * content-free and only carry a secure link — this channel sends the FULL
 * report content into the ticket. That is an explicit operator decision: the
 * OTRS instance is treated as a trusted internal case-handling system. Two
 * consequences follow and are enforced below:
 *   1. The transport is HTTPS-only (the allegation text would otherwise cross
 *      the network in cleartext).
 *   2. Articles are created NOT visible to the customer (internal channel), so
 *      the content never surfaces in any customer-facing OTRS view.
 *
 * Threading: the first message on a report opens a ticket and its OTRS
 * `TicketID` is persisted on the Report; every later message is appended as a
 * new article to that same ticket, so a whole report stays in one OTRS ticket.
 */
class OtrsMessenger implements Messenger
{
    /**
     * @param string $baseUrl GenericInterface web-service base, e.g.
     *                        https://otrs.example.org/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userLogin,
        private readonly string $password,
        private readonly string $queue,
        private readonly string $priority,
        private readonly string $state,
        private readonly string $customerUser,
        private readonly ?string $ticketType,
        private readonly int $timeout,
    ) {}

    /**
     * Build a messenger from the global `meldeplattform.otrs` config for the
     * given per-topic queue (null = use the configured default queue). Returns
     * null when the connection isn't fully configured, so a topic that opts
     * into OTRS without a configured backend is skipped (and logged) rather
     * than erroring on every report.
     */
    public static function fromConfig(?string $queue): ?self
    {
        $cfg = config('meldeplattform.otrs');
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($cfg) ? $cfg : [];

        $baseUrl = self::configString($cfg, 'base_url');
        $userLogin = self::configString($cfg, 'user_login');
        $password = self::configString($cfg, 'password');

        if ($baseUrl === null || $userLogin === null || $password === null) {
            Log::warning('OtrsMessenger: a topic routes to OTRS but the MELDE_OTRS_* connection is not fully configured; skipping.');

            return null;
        }

        return new self(
            baseUrl: $baseUrl,
            userLogin: $userLogin,
            password: $password,
            queue: ($queue !== null && $queue !== '') ? $queue : (self::configString($cfg, 'default_queue') ?? 'Raw'),
            priority: self::configString($cfg, 'default_priority') ?? '3 normal',
            state: self::configString($cfg, 'default_state') ?? 'new',
            customerUser: self::configString($cfg, 'customer_user') ?? 'safesignal',
            ticketType: self::configString($cfg, 'ticket_type'),
            timeout: self::configInt($cfg, 'timeout', 10),
        );
    }

    public function send(string $title, Message $message, string $reportUrl): void
    {
        // Echo guard: a message imported FROM an OTRS agent answer (source
        // 'otrs') must never be pushed back into the ticket it originated from,
        // or the inbound poll and this outbound channel would loop forever. The
        // reporter/admin still see it in the platform; the OTRS agent already
        // wrote it, so the ticket already has it.
        if ($message->source === 'otrs') {
            return;
        }

        // The report body travels inside this request, so refuse any non-HTTPS
        // endpoint outright. This is a permanent misconfiguration, not a
        // transient error: log and skip (don't throw) so the queued job doesn't
        // retry a send that can never be made safely.
        if (! Str::startsWith(Str::lower($this->baseUrl), 'https://')) {
            Log::error('OtrsMessenger: refusing to send report content to a non-HTTPS OTRS endpoint', [
                'base_url' => $this->baseUrl,
            ]);

            return;
        }

        $report = $message->report;
        $body = $this->articleBody($message, $reportUrl);

        // No ticket yet → open one; otherwise append this message to the ticket
        // the report already lives in. SerializesModels reloads the report on a
        // job retry, so a TicketID persisted by a prior attempt is seen here and
        // routes to TicketUpdate — i.e. a retry won't open a second ticket.
        if ($report->otrs_ticket_id === null || $report->otrs_ticket_id === '') {
            $this->createTicket($report, $title, $body);
        } else {
            $this->updateTicket($report->otrs_ticket_id, $title, $body);
        }
    }

    private function createTicket(Report $report, string $title, string $body): void
    {
        $ticket = [
            'Title' => $title,
            'Queue' => $this->queue,
            'State' => $this->state,
            'Priority' => $this->priority,
            'CustomerUser' => $this->customerUser,
        ];
        if ($this->ticketType !== null && $this->ticketType !== '') {
            $ticket['Type'] = $this->ticketType;
        }

        $data = $this->request('post', $this->endpoint(), [
            'Ticket' => $ticket,
            'Article' => $this->article($title, $body),
        ]);

        $ticketId = isset($data['TicketID']) && is_scalar($data['TicketID'])
            ? (string) $data['TicketID']
            : '';
        if ($ticketId === '') {
            throw new RuntimeException('OtrsMessenger: TicketCreate returned no TicketID');
        }

        // Persist the OTRS identifiers as the final step of a successful create
        // so later messages thread into this ticket. If the process dies between
        // the OTRS create and this write, a job retry opens ONE duplicate ticket
        // — the same at-least-once trade-off the webhook channel documents.
        $report->forceFill([
            'otrs_ticket_id' => $ticketId,
            'otrs_ticket_number' => isset($data['TicketNumber']) && is_scalar($data['TicketNumber'])
                ? (string) $data['TicketNumber']
                : null,
        ])->save();
    }

    private function updateTicket(string $ticketId, string $title, string $body): void
    {
        $this->request('patch', $this->endpoint($ticketId), [
            'TicketID' => $ticketId,
            'Article' => $this->article($title, $body),
        ]);
    }

    /**
     * Send a request to the Ticket connector and return its decoded body.
     *
     * @param 'post'|'patch' $method
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $payload): array
    {
        // Credentials go in the JSON body, which the stock GenericTicketConnector
        // reads them from. Safe only because we already enforced HTTPS above.
        $payload = ['UserLogin' => $this->userLogin, 'Password' => $this->password] + $payload;

        $request = Http::timeout($this->timeout)->acceptJson()->asJson();

        // Let transport/HTTP errors propagate (->throw) so the queued job retries.
        $response = ($method === 'patch'
            ? $request->patch($url, $payload)
            : $request->post($url, $payload))->throw();

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        // OTRS reports operational failures (bad queue, auth, invalid field) with
        // HTTP 200 and an `Error` object rather than an error status — so a bare
        // ->throw() is not enough. Surface it as an exception so the job retries.
        if (isset($data['Error'])) {
            $error = is_array($data['Error']) ? $data['Error'] : [];
            $code = isset($error['ErrorCode']) && is_scalar($error['ErrorCode']) ? (string) $error['ErrorCode'] : '?';
            $msg = isset($error['ErrorMessage']) && is_scalar($error['ErrorMessage']) ? (string) $error['ErrorMessage'] : 'unknown';

            throw new RuntimeException("OTRS GenericInterface error {$code}: {$msg}");
        }

        return $data;
    }

    private function endpoint(?string $ticketId = null): string
    {
        $base = rtrim($this->baseUrl, '/').'/Ticket';

        return $ticketId === null ? $base : $base.'/'.rawurlencode($ticketId);
    }

    /**
     * Build an internal (customer-invisible) article carrying the message text.
     *
     * @return array<string, mixed>
     */
    private function article(string $subject, string $body): array
    {
        return [
            'CommunicationChannel' => 'Internal',
            'IsVisibleForCustomer' => 0,
            'Subject' => $subject,
            'Body' => $body,
            'ContentType' => 'text/plain; charset=utf8',
        ];
    }

    private function articleBody(Message $message, string $reportUrl): string
    {
        // Operator-chosen: carry the full report content into the ticket, with a
        // trailing deep link back to the report in the platform.
        return $message->content."\n\n— — —\n".$reportUrl;
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
