<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcAuthTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example';

    private const DISCOVERY_URL = self::ISSUER.'/.well-known/openid-configuration';

    private const KID = 'test-key';

    private string $privatePem = '';

    /** @var array<string, mixed> */
    private array $jwk;

    /** @var array<string, mixed> */
    private array $discoveryDoc;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('oidc.discovery_url', self::DISCOVERY_URL);
        Config::set('oidc.client_id', 'test-client');
        Config::set('oidc.client_secret', 'test-secret');
        Config::set('oidc.redirect_uri', 'http://localhost/auth/callback');
        Config::set('oidc.post_logout_redirect_uri', 'http://localhost/');
        Config::set('oidc.scopes', ['openid', 'profile', 'email']);
        Config::set('oidc.user_id_claim', 'preferred_username');
        Config::set('oidc.discovery_cache_ttl', 60);
        Config::set('oidc.jwks_cache_ttl', 60);
        Config::set('oidc.leeway_seconds', 60);

        $keypair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keypair === false) {
            $this->fail('openssl_pkey_new() failed');
        }

        $exported = '';
        openssl_pkey_export($keypair, $exported);
        $this->privatePem = $exported;

        $details = openssl_pkey_get_details($keypair);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            $this->fail('openssl_pkey_get_details() failed');
        }
        $this->jwk = [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64url((string) $details['rsa']['n']),
            'e' => self::base64url((string) $details['rsa']['e']),
        ];

        $this->discoveryDoc = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/auth',
            'token_endpoint' => self::ISSUER.'/token',
            'userinfo_endpoint' => self::ISSUER.'/userinfo',
            'jwks_uri' => self::ISSUER.'/jwks',
            'end_session_endpoint' => self::ISSUER.'/logout',
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    public function test_login_redirects_to_idp_with_pkce_and_nonce(): void
    {
        Http::fake([
            self::DISCOVERY_URL => Http::response($this->discoveryDoc),
        ]);

        $response = $this->get('/auth/login');
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $parts = parse_url($location);
        $this->assertSame('idp.example', $parts['host'] ?? null);
        $this->assertSame('/auth', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('test-client', $query['client_id'] ?? null);
        $this->assertSame('http://localhost/auth/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertNotEmpty($query['nonce'] ?? null);
        $this->assertSame('openid profile email', $query['scope'] ?? null);

        $this->assertNotEmpty(session('oidc.code_verifier'));
        $this->assertSame($query['nonce'], session('oidc.nonce'));
    }

    public function test_callback_validates_id_token_and_writes_session(): void
    {
        $this->fakeIdpRoundtrip(claims: [
            'preferred_username' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ]);

        $response = $this->withSession([
            '_token' => 'test-state',
            'state' => 'test-state',
            'oidc.code_verifier' => 'verifier-stored',
            'oidc.nonce' => 'expected-nonce',
        ])->get('/auth/callback?code=AUTHCODE&state=test-state');

        $response->assertRedirect('/');

        $this->assertSame([
            'uid' => 'globaladmin',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ], session('auth_user'));

        $this->assertNotEmpty(session('oidc.id_token'));
    }

    public function test_callback_rejects_id_token_with_wrong_audience(): void
    {
        $this->fakeIdpRoundtrip(
            claims: [
                'preferred_username' => 'globaladmin',
                'name' => 'X',
                'email' => 'x@x',
            ],
            audOverride: 'wrong-client',
        );

        $response = $this->withSession([
            '_token' => 'test-state',
            'state' => 'test-state',
            'oidc.code_verifier' => 'v',
            'oidc.nonce' => 'expected-nonce',
        ])->get('/auth/callback?code=AUTHCODE&state=test-state');

        $response->assertStatus(403);
        $this->assertNull(session('auth_user'));
    }

    public function test_callback_rejects_id_token_with_wrong_nonce(): void
    {
        $this->fakeIdpRoundtrip(
            claims: [
                'preferred_username' => 'globaladmin',
                'name' => 'X',
                'email' => 'x@x',
            ],
            nonceOverride: 'wrong-nonce',
        );

        $response = $this->withSession([
            '_token' => 'test-state',
            'state' => 'test-state',
            'oidc.code_verifier' => 'v',
            'oidc.nonce' => 'expected-nonce',
        ])->get('/auth/callback?code=AUTHCODE&state=test-state');

        $response->assertStatus(403);
        $this->assertNull(session('auth_user'));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        Http::fake([self::DISCOVERY_URL => Http::response($this->discoveryDoc)]);

        $response = $this->withSession([
            '_token' => 'real-state',
            'state' => 'real-state',
            'oidc.code_verifier' => 'v',
            'oidc.nonce' => 'expected-nonce',
        ])->get('/auth/callback?code=AUTHCODE&state=ATTACKER-STATE');

        $response->assertStatus(403);
    }

    public function test_logout_redirects_to_end_session_endpoint_when_present(): void
    {
        Http::fake([self::DISCOVERY_URL => Http::response($this->discoveryDoc)]);

        $response = $this->withSession([
            'auth_user' => ['uid' => 'globaladmin', 'name' => 'A', 'email' => 'a@x'],
            'oidc.id_token' => 'STORED-ID-TOKEN',
        ])->get('/auth/logout');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith(self::ISSUER.'/logout', $location);
        $this->assertStringContainsString('id_token_hint=STORED-ID-TOKEN', $location);
        $this->assertStringContainsString('client_id=test-client', $location);
        $this->assertStringContainsString('post_logout_redirect_uri=', $location);

        $this->assertNull(session('auth_user'));
    }

    public function test_logout_falls_back_to_root_when_no_end_session_endpoint(): void
    {
        $doc = $this->discoveryDoc;
        unset($doc['end_session_endpoint']);
        Http::fake([self::DISCOVERY_URL => Http::response($doc)]);

        $response = $this->withSession([
            'auth_user' => ['uid' => 'globaladmin', 'name' => 'A', 'email' => 'a@x'],
        ])->get('/auth/logout');

        $response->assertRedirect('/');
        $this->assertNull(session('auth_user'));
    }

    public function test_admin_routes_accept_uid_from_preferred_username(): void
    {
        // After OIDC login the session shape matches the existing admin
        // middleware's expectation, so MELDE_ADMIN_USERS keeps working
        // unchanged when preferred_username is the configured uid claim.
        $this->withSession([
            'auth_user' => ['uid' => 'globaladmin', 'name' => 'A', 'email' => 'a@x'],
        ])->get('/newTopic/0')->assertOk();
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function fakeIdpRoundtrip(
        array $claims,
        ?string $audOverride = null,
        ?string $nonceOverride = null,
    ): void {
        $idToken = $this->signIdToken($claims, $audOverride, $nonceOverride);

        Http::fake([
            self::DISCOVERY_URL => Http::response($this->discoveryDoc),
            self::ISSUER.'/jwks' => Http::response(['keys' => [$this->jwk]]),
            self::ISSUER.'/token' => Http::response([
                'access_token' => 'access-token-123',
                'id_token' => $idToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
            self::ISSUER.'/userinfo' => Http::response($claims),
        ]);
    }

    /**
     * @param array<string, mixed> $extraClaims
     */
    private function signIdToken(
        array $extraClaims,
        ?string $audOverride = null,
        ?string $nonceOverride = null,
    ): string {
        $payload = array_merge([
            'iss' => self::ISSUER,
            'aud' => $audOverride ?? 'test-client',
            'sub' => 'subject-123',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $nonceOverride ?? 'expected-nonce',
        ], $extraClaims);

        return JWT::encode($payload, $this->privatePem, 'RS256', self::KID);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
