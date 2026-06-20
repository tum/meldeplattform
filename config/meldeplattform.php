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
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    | Global fallback retention window in days for topics that don't set their
    | own `retention_days`. Reports with no activity for longer than this are
    | deleted by the scheduled `reports:prune` command (GDPR data
    | minimisation). Leave unset/empty to keep reports forever by default.
    */
    'default_retention_days' => is_numeric(env('MELDE_DEFAULT_RETENTION_DAYS'))
        ? (int) env('MELDE_DEFAULT_RETENTION_DAYS')
        : null,

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

    'allowed_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'txt', 'csv', 'odt', 'ods', 'rtf',
        'zip', 'tar', 'gz', '7z',
        'mp4', 'webm', 'mp3', 'wav',
    ],

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
