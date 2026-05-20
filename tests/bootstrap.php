<?php

require __DIR__.'/../vendor/autoload.php';

// Laravel's `env()` is backed by phpdotenv, whose default adapter chain
// reads $_SERVER before $_ENV. When running the test-suite inside the
// Docker container, compose injects production-shaped variables
// (DB_CONNECTION=mysql, DB_HOST=db, …) straight into $_SERVER, so
// PHPUnit's <env force="true"> alone cannot dislodge them — force only
// updates $_ENV and putenv(). We overwrite $_SERVER here so tests run
// against sqlite :memory: regardless of the surrounding environment.
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF11IvCzpA1Oc=',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'APP_DEBUG' => 'true',
    'APP_URL' => 'http://localhost',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_DRIVER' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'TELESCOPE_ENABLED' => 'false',
    'MELDE_ADMIN_USERS' => 'globaladmin',
    'MELDE_DEV_LOGIN_ENABLED' => 'true',
    'OIDC_DISCOVERY_URL' => '',
    'OIDC_CLIENT_ID' => 'test-client',
    'OIDC_CLIENT_SECRET' => 'test-secret',
    'OIDC_REDIRECT_URI' => 'http://localhost/auth/callback',
    'OIDC_POST_LOGOUT_REDIRECT_URI' => 'http://localhost/',
    'OIDC_USER_ID_CLAIM' => 'preferred_username',
] as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("$key=$value");
}
