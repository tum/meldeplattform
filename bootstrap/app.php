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

        // CSRF exception for the two endpoints the IdP posts to directly: the
        // ACS (`/shib`) and single logout. Both carry an IdP-signed SAML message
        // that the SAML package validates, and neither can present a token the
        // IdP does not have. The exception is listed route by route rather than
        // as `saml/*`: that wildcard also covered `/saml/logout`, which is our
        // own UI action and must keep CSRF protection so a cross-origin request
        // cannot force an administrator's session to be destroyed.
        $middleware->preventRequestForgery(except: [
            'shib',
            'saml/slo',
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
