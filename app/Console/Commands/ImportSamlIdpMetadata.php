<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use OneLogin\Saml2\IdPMetadataParser;

/**
 * Pulls the Shibboleth/SAML IdP metadata XML and extracts the signing
 * certificate plus the SSO/SLO endpoints into a shape that can be written
 * to .env. Intended to be run manually when the IdP rotates its key.
 */
class ImportSamlIdpMetadata extends Command
{
    protected $signature = 'saml:import-idp-metadata
        {--url= : Metadata URL (defaults to SAML2_IDP_METADATA_URL)}
        {--entity-id= : Restrict to a specific IdP entityID (defaults to SAML2_IDP_ENTITYID)}
        {--write : Patch the .env file with the extracted cert and endpoints}
        {--force : Overwrite existing .env values without prompting}';

    protected $description = 'Fetch the SAML IdP metadata XML and extract x509 cert + SSO/SLO URLs.';

    public function handle(): int
    {
        $urlOption = $this->option('url');
        $entityIdOption = $this->option('entity-id');
        $url = is_string($urlOption) && $urlOption !== ''
            ? $urlOption
            : Config::string('saml2.idp.metadataUrl', '');
        $entityId = is_string($entityIdOption) && $entityIdOption !== ''
            ? $entityIdOption
            : Config::string('saml2.idp.entityId', '');

        if ($url === '') {
            $this->error('No metadata URL. Set SAML2_IDP_METADATA_URL or pass --url=.');

            return self::FAILURE;
        }

        $this->line("Fetching IdP metadata from: <info>$url</info>");
        if ($entityId !== '') {
            $this->line("Restricting to entityID:   <info>$entityId</info>");
        }

        try {
            // validatePeer=true: refuse to accept TLS certs that don't chain to a
            // trusted root. Without this, a MITM on the metadata fetch could
            // inject an attacker-controlled signing cert and forge assertions.
            $info = IdPMetadataParser::parseRemoteXML(
                $url,
                $entityId !== '' ? $entityId : null,
                null,
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                true,
            );
        } catch (\Throwable $e) {
            $this->error('Metadata fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, mixed> $idp */
        $idp = is_array($info['idp'] ?? null) ? $info['idp'] : [];
        $cert = is_string($idp['x509cert'] ?? null) ? trim($idp['x509cert']) : '';
        $extractedEntityId = is_string($idp['entityId'] ?? null) ? $idp['entityId'] : '';
        $sso = is_array($idp['singleSignOnService'] ?? null) ? $idp['singleSignOnService'] : [];
        $slo = is_array($idp['singleLogoutService'] ?? null) ? $idp['singleLogoutService'] : [];
        $ssoUrl = is_string($sso['url'] ?? null) ? $sso['url'] : '';
        $sloUrl = is_string($slo['url'] ?? null) ? $slo['url'] : '';

        if ($cert === '') {
            $this->error('Metadata parsed, but no x509 signing certificate found.');

            return self::FAILURE;
        }

        // Strip any whitespace/newlines so the cert fits on a single .env line.
        $certOneLine = preg_replace('/\s+/', '', $cert) ?? '';

        // SHA-256 fingerprint over the DER-encoded cert. Print it so operators
        // can cross-check against whatever the IdP operator publishes
        // out-of-band (e.g. TUM support portal).
        $fingerprint = '';
        $der = base64_decode($certOneLine, true);
        if ($der !== false) {
            $fingerprint = strtoupper(chunk_split(hash('sha256', $der), 2, ':'));
            $fingerprint = rtrim($fingerprint, ':');
        }

        $this->newLine();
        $this->info('Extracted IdP metadata');
        $this->line('  entityID: '.$extractedEntityId);
        $this->line('  SSO URL:  '.$ssoUrl);
        $this->line('  SLO URL:  '.$sloUrl);
        $this->line('  Cert SHA-256: '.($fingerprint !== '' ? $fingerprint : '(could not compute)'));
        $this->newLine();
        $this->line('<comment>Verify the fingerprint out-of-band before trusting this cert.</comment>');

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Set in .env:');
            $this->line('  SAML2_IDP_X509CERT='.$certOneLine);
            if ($ssoUrl !== '') {
                $this->line('  SAML2_IDP_SSO_URL='.$ssoUrl);
            }
            if ($sloUrl !== '') {
                $this->line('  SAML2_IDP_SLO_URL='.$sloUrl);
            }
            if ($extractedEntityId !== '') {
                $this->line('  SAML2_IDP_ENTITYID='.$extractedEntityId);
            }
            $this->newLine();
            $this->line('Re-run with --write to patch .env in place.');

            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            $this->error("Cannot write to $envPath.");

            return self::FAILURE;
        }

        $updates = array_filter([
            'SAML2_IDP_X509CERT' => $certOneLine,
            'SAML2_IDP_SSO_URL' => $ssoUrl,
            'SAML2_IDP_SLO_URL' => $sloUrl,
            'SAML2_IDP_ENTITYID' => $extractedEntityId,
        ], static fn (string $v): bool => $v !== '');

        $force = (bool) $this->option('force');
        $written = $this->patchEnv($envPath, $updates, $force);
        $this->info("Patched .env ($written key(s) updated).");

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $updates
     */
    private function patchEnv(string $path, array $updates, bool $force): int
    {
        $contents = (string) file_get_contents($path);
        $written = 0;

        foreach ($updates as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $line = $key.'='.$this->quoteIfNeeded($value);

            if (preg_match($pattern, $contents, $m) === 1) {
                $existing = trim(substr($m[0], strlen($key) + 1));
                $existing = trim($existing, "\"'");
                if ($existing === $value) {
                    continue;
                }
                if ($existing !== '' && ! $force && ! $this->confirm("Overwrite existing $key in .env?", false)) {
                    $this->line("  skipped $key");

                    continue;
                }
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
            $written++;
        }

        file_put_contents($path, $contents);

        return $written;
    }

    private function quoteIfNeeded(string $value): string
    {
        // Env parsers treat spaces and # as special; quote defensively.
        if (preg_match('/[\s#"\']/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
