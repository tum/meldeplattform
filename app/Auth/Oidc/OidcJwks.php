<?php

namespace App\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;

class OidcJwks
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly CacheRepository $cache,
        private array $config,
    ) {}

    /**
     * @return array<string, Key>
     */
    public function keys(): array
    {
        $jwks = $this->fetchJwks();
        /** @var array<string, Key> $keys */
        $keys = JWK::parseKeySet($jwks);
        if ($keys === []) {
            throw new OidcAuthenticationException('JWKS contains no usable signing keys.');
        }

        return $keys;
    }

    public function invalidate(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Validate an ID token signature and a minimal set of claims.
     *
     * @return array<string, mixed>
     */
    public function validate(string $idToken, string $expectedNonce): array
    {
        $leewayRaw = $this->config['leeway_seconds'] ?? 60;
        JWT::$leeway = is_int($leewayRaw) || is_string($leewayRaw) ? (int) $leewayRaw : 60;

        try {
            $decoded = JWT::decode($idToken, $this->keys());
        } catch (\Throwable $e) {
            // Invalidate the JWKS cache so a key rotation self-heals on retry.
            $this->invalidate();
            throw new OidcAuthenticationException(
                'id_token signature invalid: '.$e->getMessage(),
                previous: $e,
            );
        }
        $claims = (array) $decoded;

        $expectedIss = self::strOrEmpty($this->config['issuer'] ?? null);
        if ($expectedIss === '') {
            $expectedIss = $this->discovery->issuer();
        }
        $actualIss = self::strOrEmpty($claims['iss'] ?? null);
        if ($actualIss !== $expectedIss) {
            throw new OidcAuthenticationException(
                "id_token issuer mismatch ({$actualIss} != {$expectedIss}).",
            );
        }

        $clientId = self::strOrEmpty($this->config['client_id'] ?? null);
        $aud = $claims['aud'] ?? null;
        $audOk = is_array($aud) ? in_array($clientId, $aud, true) : $aud === $clientId;
        if (! $audOk) {
            throw new OidcAuthenticationException('id_token audience mismatch.');
        }

        if (isset($claims['azp']) && self::strOrEmpty($claims['azp']) !== $clientId) {
            throw new OidcAuthenticationException('id_token azp mismatch.');
        }

        if ($expectedNonce !== '' && self::strOrEmpty($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new OidcAuthenticationException('id_token nonce mismatch.');
        }

        return $claims;
    }

    private static function strOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    private function fetchJwks(): array
    {
        $uri = $this->discovery->jwksUri();
        $ttlRaw = $this->config['jwks_cache_ttl'] ?? 3600;
        $ttl = is_int($ttlRaw) || is_string($ttlRaw) ? (int) $ttlRaw : 3600;

        /** @var array{keys: list<array<string, mixed>>} */
        return $this->cache->remember($this->cacheKey(), $ttl, function () use ($uri): array {
            $response = Http::timeout(10)
                ->withOptions(['verify' => true])
                ->acceptJson()
                ->get($uri);
            if (! $response->successful()) {
                throw new OidcAuthenticationException(
                    "JWKS fetch failed: HTTP {$response->status()}",
                );
            }
            $data = $response->json();
            if (! is_array($data) || ! isset($data['keys']) || ! is_array($data['keys'])) {
                throw new OidcAuthenticationException('JWKS document malformed.');
            }

            return $data;
        });
    }

    private function cacheKey(): string
    {
        return 'oidc.jwks.'.sha1($this->discovery->jwksUri());
    }
}
