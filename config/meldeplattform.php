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
    | Dev login bypass
    |--------------------------------------------------------------------------
    | When true AND APP_ENV != "production", an in-app form at /dev/login
    | seeds the SAML session without contacting the IdP. Must stay off in prod.
    */
    'dev_login_enabled' => filter_var(env('MELDE_DEV_LOGIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

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
