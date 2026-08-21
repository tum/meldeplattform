<?php

require __DIR__.'/../vendor/autoload.php';

// Laravel's `env()` is backed by phpdotenv, whose default adapter chain
// reads $_SERVER before $_ENV. When running the test-suite inside the
// Docker container, compose injects production-shaped variables
// (DB_CONNECTION=mysql, DB_HOST=db, …) straight into $_SERVER, so
// PHPUnit's <env force="true"> alone cannot dislodge them — force only
// updates $_ENV and putenv(). We overwrite $_SERVER here instead.
$forced = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF11IvCzpA1Oc=',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'TELESCOPE_ENABLED' => 'false',
    'MELDE_ADMIN_USERS' => 'globaladmin',
    'MELDE_DEV_LOGIN_ENABLED' => 'true',
];

// The database connection is the one group we cannot force unconditionally:
// the CI matrix has to be able to point the suite at a real MariaDB/MySQL.
// Opting in happens through MELDE_TEST_DB — a variable docker-compose never
// sets — so an ambient, container-provided DB_* environment still cannot
// leak into the suite on its own.
$targetDb = $_SERVER['MELDE_TEST_DB'] ?? getenv('MELDE_TEST_DB');
$targetDb = is_string($targetDb) && $targetDb !== '' ? $targetDb : 'sqlite';

if ($targetDb === 'sqlite') {
    $forced += [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_HOST' => '',
        'DB_PORT' => '',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
    ];
} else {
    // Honour the DB_* values the caller exported; only the driver itself is
    // pinned, so a matrix leg just has to name host/database/credentials.
    $forced['DB_CONNECTION'] = $targetDb;
}

foreach ($forced as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("$key=$value");
}
