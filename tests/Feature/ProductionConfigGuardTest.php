<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards AppServiceProvider::assertProductionInvariants(). The APP_DEBUG check
 * fails closed on the production HTTP path: a debug error page leaks env
 * (APP_KEY — which HMACs report receipt codes — plus DB/OTRS/SAML credentials).
 * Console deploy commands (config:cache, migrate) are exempt so the pipeline
 * isn't blocked. Tested through the pure invariant method with plain values.
 */
class ProductionConfigGuardTest extends TestCase
{
    private function invoke(bool $isConsole, bool $sessionSecure, bool $appDebug, string $queueDefault = 'database'): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, 'assertProductionInvariants');
        $method->setAccessible(true);
        $method->invoke(null, $isConsole, $sessionSecure, $appDebug, $queueDefault);
    }

    public function test_throws_when_app_debug_true_over_http(): void
    {
        try {
            $this->invoke(isConsole: false, sessionSecure: true, appDebug: true);
            $this->fail('Expected RuntimeException when APP_DEBUG is on in production HTTP');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
        }
    }

    public function test_throws_when_session_cookie_insecure_over_http(): void
    {
        try {
            $this->invoke(isConsole: false, sessionSecure: false, appDebug: false);
            $this->fail('Expected RuntimeException when the session cookie is insecure in production HTTP');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SESSION_SECURE_COOKIE', $e->getMessage());
        }
    }

    public function test_allows_app_debug_true_on_console(): void
    {
        // Deploy commands run in console with whatever APP_DEBUG is set — the
        // HTTP-only invariants must not block them.
        $this->invoke(isConsole: true, sessionSecure: false, appDebug: true);

        $this->addToAssertionCount(1); // reached here → no exception thrown
    }

    public function test_allows_secure_non_debug_over_http(): void
    {
        $this->invoke(isConsole: false, sessionSecure: true, appDebug: false);

        $this->addToAssertionCount(1);
    }
}
