<?php

use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        ]);

        // CSRF exception for the SAML ACS endpoint. The SAML response is
        // signed by the IdP and validated by the SAML package.
        $middleware->preventRequestForgery(except: [
            'shib',
            'saml/*',
        ]);

        // Unauthenticated web requests get bounced to the SAML SSO start
        // (or the dev-login page when available); JSON requests still get a
        // 401 via Laravel's default AuthenticationException handler.
        $middleware->redirectGuestsTo(static function (Request $request): ?string {
            if ($request->expectsJson()) {
                return null;
            }

            return ! app()->environment('production')
                && (bool) config('meldeplattform.dev_login_enabled', false)
                ? '/dev/login'
                : '/saml/out';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
