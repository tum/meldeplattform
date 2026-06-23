<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Branding / content
    |--------------------------------------------------------------------------
    */
    'title' => [
        'de' => 'TUM SafeSignal',
        'en' => 'TUM SafeSignal',
    ],
    'subtitle' => [
        'de' => 'Whistleblowing & IT Security Reporting System',
        'en' => 'Whistleblowing & IT Security Reporting System',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin users
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of SAML UIDs (e.g. "ge42tum") that get global
    | admin rights (can create topics, see every report, etc.).
    |
    */
    'admin_users' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MELDE_ADMIN_USERS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => (int) env('MELDE_MAX_UPLOAD_MB', 10),

    /*
    |--------------------------------------------------------------------------
    | EU Whistleblowing Directive (2019/1937) deadlines
    |--------------------------------------------------------------------------
    |
    | Acknowledge a report within 7 days; provide feedback within 3 months
    | (≈90 days). Both measured from the report's creation time.
    |
    */
    'acknowledgement_deadline_days' => (int) env('MELDE_ACK_DEADLINE_DAYS', 7),
    'feedback_deadline_days' => (int) env('MELDE_FEEDBACK_DEADLINE_DAYS', 90),

    /*
    | Lead time (in days) before a deadline at which the scheduled
    | `reports:remind` command starts emailing the topic's case handlers, so a
    | report is flagged *before* — not only after — it breaches the window.
    */
    'reminder_ack_lead_days' => (int) env('MELDE_REMINDER_ACK_LEAD_DAYS', 2),
    'reminder_feedback_lead_days' => (int) env('MELDE_REMINDER_FEEDBACK_LEAD_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    | Global fallback retention window in days for topics that don't set their
    | own `retention_days`. Concluded reports (Done/Spam) whose `closed_at` is
    | older than this are deleted by the scheduled `reports:prune` command.
    |
    | Defaults to 1095 days (3 years), matching HinSchG §11(5), which requires
    | report documentation to be deleted three years after the procedure is
    | concluded. Set MELDE_DEFAULT_RETENTION_DAYS=0 to disable the global
    | default and keep reports until a per-topic window is configured.
    */
    'default_retention_days' => (static function (): ?int {
        $raw = env('MELDE_DEFAULT_RETENTION_DAYS');
        if ($raw !== null && $raw !== '' && ! is_numeric($raw)) {
            throw new InvalidArgumentException(
                'MELDE_DEFAULT_RETENTION_DAYS must be a number (or unset). Got: '.(string) $raw,
            );
        }

        if (is_numeric($raw)) {
            $days = (int) $raw;
            if ($days < 0) {
                throw new InvalidArgumentException(
                    'MELDE_DEFAULT_RETENTION_DAYS must be a non-negative number. Got: '.(string) $raw,
                );
            }

            return $days > 0 ? $days : null;
        }

        return 1095;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Dev login bypass
    |--------------------------------------------------------------------------
    | When true AND APP_ENV != "production", an in-app form at /dev/login
    | seeds the SAML session without contacting the IdP. Must stay off in prod.
    */
    'dev_login_enabled' => filter_var(env('MELDE_DEV_LOGIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Webhook notification signing
    |--------------------------------------------------------------------------
    | Shared secret used to HMAC-SHA256 sign outgoing webhook payloads so the
    | receiving endpoint can verify the request really came from us. Sent in
    | the `X-SafeSignal-Signature: sha256=<hex>` header. Leave empty to send
    | webhooks unsigned (not recommended).
    */
    'webhook_secret' => (string) env('MELDE_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | OTRS / Znuny ticket integration
    |--------------------------------------------------------------------------
    | Connection to an OTRS/Znuny GenericInterface "Ticket Connector" REST web
    | service. A topic opts in per-contacts (`contacts.otrs.queue`); the keys
    | below hold the shared connection plus ticket defaults.
    |
    | UNLIKE the email/webhook channels, this one pushes the FULL report content
    | into the ticket — so `base_url` MUST be https (the messenger refuses any
    | other scheme). Leave `base_url` empty to disable OTRS entirely; topics
    | that request it are then skipped with a logged warning.
    |
    | `base_url` is the web-service base, e.g.
    |   https://otrs.example.org/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnectorREST
    */
    'otrs' => [
        'base_url' => (string) env('MELDE_OTRS_BASE_URL', ''),
        'user_login' => (string) env('MELDE_OTRS_USER_LOGIN', ''),
        'password' => (string) env('MELDE_OTRS_PASSWORD', ''),
        'default_queue' => (string) env('MELDE_OTRS_QUEUE', 'Raw'),
        'default_priority' => (string) env('MELDE_OTRS_PRIORITY', '3 normal'),
        'default_state' => (string) env('MELDE_OTRS_STATE', 'new'),
        'customer_user' => (string) env('MELDE_OTRS_CUSTOMER_USER', 'safesignal'),
        'ticket_type' => (string) env('MELDE_OTRS_TYPE', ''),
        'timeout' => (int) env('MELDE_OTRS_TIMEOUT', 10),

        // Inbound: when enabled, the scheduled `otrs:poll-replies` command polls
        // each report's ticket (TicketGet) for NEW agent answers and mirrors
        // them back as admin replies the reporter sees in the platform. An OTRS
        // agent marks an article as "for the reporter" simply by making it
        // customer-visible (IsVisibleForCustomer=1) — our own pushes are always
        // internal, so they are never re-imported. Needs the TicketGet operation
        // enabled on the web service; the agent's existing rw on the queue
        // already permits the read. Disabled by default.
        'inbound_enabled' => (bool) env('MELDE_OTRS_INBOUND_ENABLED', false),
    ],

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'txt', 'csv', 'odt', 'ods', 'rtf',
        'zip', 'tar', 'gz', '7z',
        'mp4', 'webm', 'mp3', 'wav', 'ogg', 'm4a',
    ],

    /*
    |--------------------------------------------------------------------------
    | Oral reporting (voice message) intake
    |--------------------------------------------------------------------------
    | Extensions accepted by an `audio` field — the EU Directive Art. 9(2) /
    | HinSchG §16 oral-reporting channel. The set covers what browsers produce
    | via in-page recording (webm/mp4/ogg) plus common uploads (mp3/wav/m4a).
    | NOTE: audio is NOT re-encoded to strip metadata (only raster images are),
    | so reporters are warned that a recording may carry identifying metadata.
    */
    'allowed_audio_extensions' => ['webm', 'mp4', 'ogg', 'mp3', 'wav', 'm4a'],

    /*
    |--------------------------------------------------------------------------
    | Markdown-rendered pages (imprint / privacy).
    |--------------------------------------------------------------------------
    | Set to an empty string to fall back to resources/markdown/{imprint,privacy}.md.
    */
    'imprint' => '',
    'privacy' => '',

    'imprint_file' => resource_path('markdown/imprint.md'),
    'privacy_file' => resource_path('markdown/privacy.md'),
];
