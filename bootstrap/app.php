<?php

use App\Http\Middleware\EnsureGlobalAdmin;
use App\Http\Middleware\EnsureTopicAdmin;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ShareViewData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SecurityHeaders::class,
            LocaleMiddleware::class,
            ShareViewData::class,
        ]);

        // CSRF exception for the SAML ACS endpoint. The SAML response is
        // signed by the IdP and validated by the SAML package.
        $middleware->preventRequestForgery(except: [
            'shib',
            'saml/*',
        ]);

        $middleware->alias([
            'topic.admin' => EnsureTopicAdmin::class,
            'admin' => EnsureGlobalAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
