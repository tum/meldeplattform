<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostic for the OIDC IdP. Fetches the discovery document (and
 * optionally the JWKS) and prints the endpoints + key thumbprints so
 * operators can sanity-check what the IdP exposes.
 *
 * Unlike `saml:import-idp-metadata`, this command never has a `--write`
 * mode: OIDC discovery is the IdP-of-truth at request time and we
 * deliberately do not bake key material into `.env`.
 */
class OidcTestDiscovery extends Command
{
    protected $signature = 'oidc:test-discovery
        {--url= : Discovery URL (defaults to OIDC_DISCOVERY_URL)}
        {--show-jwks : Also fetch and summarize the JWKS}';

    protected $description = 'Fetch OIDC discovery + JWKS and print endpoints / key thumbprints.';

    public function handle(): int
    {
        $urlOption = $this->option('url');
        $url = is_string($urlOption) && $urlOption !== ''
            ? $urlOption
            : Config::string('oidc.discovery_url', '');

        if ($url === '') {
            $this->error('No discovery URL. Set OIDC_DISCOVERY_URL or pass --url=.');

            return self::FAILURE;
        }

        $this->line("Fetching OIDC discovery from: <info>$url</info>");
        try {
            $response = Http::timeout(10)
                ->withOptions(['verify' => true])
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            $this->error('Discovery fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $response->successful()) {
            $this->error("Discovery fetch failed: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $doc = $response->json();
        if (! is_array($doc)) {
            $this->error('Discovery document is not a JSON object.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Discovery document');
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri', 'end_session_endpoint'] as $key) {
            $value = is_string($doc[$key] ?? null) ? $doc[$key] : '(not set)';
            $this->line(sprintf('  %-24s %s', $key.':', $value));
        }

        $scopes = $doc['scopes_supported'] ?? null;
        if (is_array($scopes)) {
            $this->line('  scopes_supported:        '.implode(', ', array_filter($scopes, 'is_string')));
        }

        $challenges = $doc['code_challenge_methods_supported'] ?? null;
        if (is_array($challenges)) {
            $list = array_filter($challenges, 'is_string');
            $this->line('  pkce methods:            '.implode(', ', $list));
            if (! in_array('S256', $list, true)) {
                $this->warn('  WARNING: IdP does not advertise S256 PKCE support.');
            }
        }

        if (! $this->option('show-jwks')) {
            return self::SUCCESS;
        }

        $jwksUri = is_string($doc['jwks_uri'] ?? null) ? $doc['jwks_uri'] : '';
        if ($jwksUri === '') {
            $this->error('Discovery document is missing jwks_uri.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Fetching JWKS from: <info>$jwksUri</info>");
        try {
            $jwksResponse = Http::timeout(10)
                ->withOptions(['verify' => true])
                ->acceptJson()
                ->get($jwksUri);
        } catch (\Throwable $e) {
            $this->error('JWKS fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $jwksResponse->successful()) {
            $this->error("JWKS fetch failed: HTTP {$jwksResponse->status()}");

            return self::FAILURE;
        }

        $jwks = $jwksResponse->json();
        if (! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            $this->error('JWKS document is malformed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('JWKS: %d key(s)', count($jwks['keys'])));
        foreach ($jwks['keys'] as $i => $key) {
            if (! is_array($key)) {
                continue;
            }
            $kid = is_string($key['kid'] ?? null) ? $key['kid'] : '(no kid)';
            $alg = is_string($key['alg'] ?? null) ? $key['alg'] : '(no alg)';
            $use = is_string($key['use'] ?? null) ? $key['use'] : '(no use)';
            $this->line(sprintf('  [%d] kid=%s  alg=%s  use=%s', $i, $kid, $alg, $use));
            $thumbprint = self::rfc7638Thumbprint($key);
            if ($thumbprint !== null) {
                $this->line('      sha256: '.$thumbprint);
            }
        }
        $this->newLine();
        $this->line('<comment>Cross-check the SHA-256 thumbprint(s) against an out-of-band source (e.g. TUM support).</comment>');

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $key
     */
    private static function rfc7638Thumbprint(array $key): ?string
    {
        $kty = $key['kty'] ?? null;
        if (! is_string($kty)) {
            return null;
        }
        $canonical = match ($kty) {
            'RSA' => isset($key['e'], $key['n']) && is_string($key['e']) && is_string($key['n'])
                ? ['e' => $key['e'], 'kty' => 'RSA', 'n' => $key['n']]
                : null,
            'EC' => isset($key['crv'], $key['x'], $key['y']) && is_string($key['crv']) && is_string($key['x']) && is_string($key['y'])
                ? ['crv' => $key['crv'], 'kty' => 'EC', 'x' => $key['x'], 'y' => $key['y']]
                : null,
            default => null,
        };
        if ($canonical === null) {
            return null;
        }
        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        $hash = hash('sha256', $json);

        return strtoupper(rtrim(chunk_split($hash, 2, ':'), ':'));
    }
}
