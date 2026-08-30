<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Logging a case handler out is a state change, so it must not be reachable by
 * a request another site can make on their behalf (CWE-352). `/saml/slo`
 * already carried that guard; `/saml/logout` did not — it was a GET, and the
 * `saml/*` CSRF exemption covered it besides, so a bare `<img src>` on any page
 * an administrator visited ended their session.
 */
class ForcedLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_saml_logout_is_not_reachable_by_a_cross_origin_get(): void
    {
        $user = User::create(['uid' => 'a', 'name' => 'A', 'email' => 'a@x']);

        $this->actingAs($user)->get('/saml/logout')->assertMethodNotAllowed();

        $this->assertTrue(Auth::check(), 'a GET to /saml/logout destroyed the session');
    }

    public function test_saml_logout_still_keeps_csrf_protection(): void
    {
        // The exemption list must not cover our own logout action. `saml/slo`
        // stays exempt: the IdP posts a signed SAML message there and cannot
        // carry our token.
        $except = $this->exemptFromCsrf();

        $this->assertContains('shib', $except);
        $this->assertContains('saml/slo', $except);
        $this->assertNotContains('saml/*', $except);
    }

    public function test_slo_still_refuses_a_request_without_a_saml_message(): void
    {
        $this->get('/saml/slo')->assertStatus(400);
    }

    public function test_the_header_offers_logout_as_a_post_form(): void
    {
        $user = User::create(['uid' => 'b', 'name' => 'B', 'email' => 'b@x']);
        $logoutUrl = Route::has('dev.logout') ? route('dev.logout') : route('saml.logout');

        $html = (string) $this->actingAs($user)->get('/')->getContent();

        $this->assertStringContainsString('<form method="POST" action="'.$logoutUrl.'"', $html);
        $this->assertStringNotContainsString('<a href="'.$logoutUrl.'"', $html);
    }

    /**
     * The URIs bootstrap/app.php exempts from CSRF verification.
     *
     * @return list<string>
     */
    private function exemptFromCsrf(): array
    {
        $reflection = new \ReflectionProperty(PreventRequestForgery::class, 'neverVerify');

        /** @var list<string> $except */
        $except = $reflection->getValue();

        return $except;
    }
}
