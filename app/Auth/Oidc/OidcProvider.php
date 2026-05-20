<?php

namespace App\Auth\Oidc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

class OidcProvider extends AbstractProvider
{
    /** @var string */
    protected $scopeSeparator = ' ';

    /** @var list<string> */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * @param array<string, mixed> $oidcConfig
     */
    public function __construct(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        private readonly OidcDiscovery $discovery,
        private readonly OidcJwks $jwks,
        private readonly array $oidcConfig,
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);

        /** @var list<string> $scopes */
        $scopes = $oidcConfig['scopes'] ?? $this->scopes;
        if ($scopes !== []) {
            $this->scopes = $scopes;
        }
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->discovery->authorizationEndpoint(), $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->discovery->tokenEndpoint();
    }

    /**
     * @param string|null $state
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);

        // PKCE: stash the verifier in session, send the challenge.
        $verifier = self::generateCodeVerifier();
        $this->request->session()->put('oidc.code_verifier', $verifier);
        $fields['code_challenge'] = self::codeChallenge($verifier);
        $fields['code_challenge_method'] = 'S256';

        // OIDC nonce: stash for ID-token validation.
        $nonce = bin2hex(random_bytes(16));
        $this->request->session()->put('oidc.nonce', $nonce);
        $fields['nonce'] = $nonce;

        return $fields;
    }

    /**
     * @param string $code
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);
        $fields['code_verifier'] = self::strOrEmpty($this->request->session()->pull('oidc.code_verifier', ''));

        return $fields;
    }

    /**
     * @param string $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $endpoint = $this->discovery->userinfoEndpoint();
        if ($endpoint === null) {
            return [];
        }

        $response = Http::timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->get($endpoint);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param string $code
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $response = Http::timeout(10)
            ->asForm()
            ->acceptJson()
            ->post($this->getTokenUrl(), $this->getTokenFields($code));

        if (! $response->successful()) {
            throw new OidcAuthenticationException(
                "token exchange failed: HTTP {$response->status()}",
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new OidcAuthenticationException('token endpoint returned non-JSON.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Required by AbstractProvider but unused — `user()` is overridden so
     * no caller invokes this. Kept as a thin no-op for type completeness.
     *
     * @param array<string, mixed> $user
     */
    protected function mapUserToObject(array $user): SocialiteUser
    {
        return (new SocialiteUser)->setRaw($user);
    }

    public function user(): SocialiteUser
    {
        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $accessToken = self::strOrEmpty($response['access_token'] ?? null);
        $idToken = self::strOrEmpty($response['id_token'] ?? null);
        if ($idToken === '') {
            throw new OidcAuthenticationException('id_token missing from token response.');
        }

        $expectedNonce = self::strOrEmpty($this->request->session()->pull('oidc.nonce', ''));
        $idClaims = $this->jwks->validate($idToken, $expectedNonce);

        $userinfo = [];
        if ($accessToken !== '' && $this->discovery->userinfoEndpoint() !== null) {
            try {
                $userinfo = $this->getUserByToken($accessToken);
            } catch (\Throwable) {
                // userinfo failure must not block login when the id_token is already validated
                $userinfo = [];
            }
        }

        $merged = array_merge($idClaims, $userinfo, ['_id_token' => $idToken]);

        $idClaim = self::strOrDefault($this->oidcConfig['user_id_claim'] ?? null, 'preferred_username');
        $id = $merged[$idClaim] ?? $merged['sub'] ?? null;

        $user = (new SocialiteUser)->setRaw($merged)->map([
            'id' => $id,
            'nickname' => $merged['preferred_username'] ?? null,
            'name' => $merged['name'] ?? null,
            'email' => $merged['email'] ?? null,
            'avatar' => null,
        ]);

        return $user
            ->setToken($accessToken)
            ->setRefreshToken(self::strOrEmpty($response['refresh_token'] ?? null))
            ->setExpiresIn(self::intOrZero($response['expires_in'] ?? null));
    }

    public function buildEndSessionUrl(?string $idTokenHint): ?string
    {
        $endpoint = $this->discovery->endSessionEndpoint();
        if ($endpoint === null) {
            return null;
        }

        $params = array_filter([
            'client_id' => $this->clientId,
            'post_logout_redirect_uri' => self::strOrEmpty($this->oidcConfig['post_logout_redirect_uri'] ?? null),
            'id_token_hint' => $idTokenHint ?? '',
        ], static fn (string $v): bool => $v !== '');

        $sep = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$sep.http_build_query($params);
    }

    private static function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function strOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function strOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function intOrZero(mixed $value): int
    {
        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : 0;
    }
}
