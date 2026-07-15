<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
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
    // `sync` is the correct production driver here (no workers, no jobs table),
    // so it is the right default for tests about the other invariants.
    private function invoke(bool $isConsole, bool $sessionSecure, bool $appDebug, string $queueDefault = 'sync'): void
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

    /**
     * The queue check used to warn on `sync` and tell operators to switch to
     * `database` — i.e. it advised the exact change that stranded every
     * notification when it was applied server-side, and it said so on every
     * single request into an unrotated log.
     */
    public function test_does_not_warn_when_the_queue_is_correctly_sync(): void
    {
        $logger = Log::spy();

        $this->invoke(isConsole: false, sessionSecure: true, appDebug: false, queueDefault: 'sync');

        $logger->shouldNotHaveReceived('warning');
    }

    public function test_warns_when_the_queue_is_not_sync(): void
    {
        // No worker runs here and no jobs table ships, so a non-sync driver
        // enqueues notifications that are never delivered.
        $logger = Log::spy();

        $this->invoke(isConsole: false, sessionSecure: true, appDebug: false, queueDefault: 'database');

        $logger->shouldHaveReceived('warning');
    }

    public function test_a_non_sync_queue_warns_rather_than_blocks_the_deploy(): void
    {
        // It is a misconfiguration, not a secret leak: warn, don't throw, so a
        // console deploy still completes.
        $this->invoke(isConsole: true, sessionSecure: true, appDebug: false, queueDefault: 'redis');

        $this->addToAssertionCount(1);
    }
}
