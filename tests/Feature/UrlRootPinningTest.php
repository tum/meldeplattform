<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the AppServiceProvider hardening that pins generated URLs to APP_URL.
 *
 * The bug this prevents: mail links (deadline digests, OTRS replies) pointed at
 * localhost on production because route()/url() fall back to the request host,
 * which is absent on the CLI and forgeable behind a reverse proxy.
 */
class UrlRootPinningTest extends TestCase
{
    public function test_generated_urls_use_app_url_host_not_the_request_host(): void
    {
        // APP_URL is http://localhost in the test env (see tests/bootstrap.php).
        // A generated link must resolve to that canonical host even when the
        // incoming request carries a different Host header — the reverse-proxy
        // situation that previously leaked into mail links.
        Route::get('/__pinning-probe', static fn (): string => url('/dashboard'));

        $response = $this->get('/__pinning-probe', ['Host' => 'evil.example.test']);

        $response->assertOk();
        $response->assertSee('http://localhost/dashboard', false);
        $response->assertDontSee('evil.example.test', false);
    }
}
