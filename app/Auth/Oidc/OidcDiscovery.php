<?php

namespace App\Auth\Oidc;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;

class OidcDiscovery
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $urlRaw = $this->config['discovery_url'] ?? '';
        $url = is_string($urlRaw) ? $urlRaw : '';
        if ($url === '') {
            return $this->manualEndpoints();
        }

        $ttlRaw = $this->config['discovery_cache_ttl'] ?? 3600;
        $ttl = is_int($ttlRaw) || is_string($ttlRaw) ? (int) $ttlRaw : 3600;
        $key = 'oidc.discovery.'.sha1($url);

        /** @var array<string, mixed> */
        return $this->cache->remember($key, $ttl, function () use ($url): array {
            $response = Http::timeout(10)
                ->withOptions(['verify' => true])
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                throw new OidcAuthenticationException(
                    "discovery fetch failed: HTTP {$response->status()}",
                );
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new OidcAuthenticationException('discovery document is not a JSON object.');
            }
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (! isset($data[$required]) || ! is_string($data[$required]) || $data[$required] === '') {
                    throw new OidcAuthenticationException("discovery missing required field '{$required}'.");
                }
            }

            return $data;
        });
    }

    public function authorizationEndpoint(): string
    {
        return $this->stringField('authorization_endpoint');
    }

    public function tokenEndpoint(): string
    {
        return $this->stringField('token_endpoint');
    }

    public function userinfoEndpoint(): ?string
    {
        $v = $this->document()['userinfo_endpoint'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function jwksUri(): string
    {
        return $this->stringField('jwks_uri');
    }

    public function endSessionEndpoint(): ?string
    {
        $v = $this->document()['end_session_endpoint'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function issuer(): string
    {
        return $this->stringField('issuer');
    }

    private function stringField(string $key): string
    {
        $v = $this->document()[$key] ?? null;

        return is_string($v) ? $v : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function manualEndpoints(): array
    {
        $manual = (array) ($this->config['manual_endpoints'] ?? []);
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! isset($manual[$field]) || ! is_string($manual[$field]) || $manual[$field] === '') {
                throw new OidcAuthenticationException(
                    "not configured: set OIDC_DISCOVERY_URL or fill manual_endpoints['{$field}'].",
                );
            }
        }

        return $manual;
    }
}
