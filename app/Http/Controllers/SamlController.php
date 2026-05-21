<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth as OneLoginAuth;

/**
 * Thin SAML SP implementation backed directly by onelogin/php-saml. Mirrors the
 * behaviour of the Go /saml/{metadata,out,slo} + /shib handlers.
 */
class SamlController extends Controller
{
    public function metadata(): Response
    {
        $auth = $this->newAuth();
        $metadata = $auth->getSettings()->getSPMetadata();

        return response($metadata, 200, ['Content-Type' => 'text/xml']);
    }

    public function login(): RedirectResponse
    {
        $auth = $this->newAuth();
        $redirectUrl = $auth->login(
            url('/'), // returnTo / RelayState
            [],       // parameters
            false,    // forceAuthn
            false,    // isPassive
            true,      // stay = true: kein exit(), URL zurückgeben
        );

        session(['saml_request_id' => $auth->getLastRequestID()]);
        session()->save();

        return redirect()->away($redirectUrl);

    }

    public function logout(Request $request): RedirectResponse
    {
        $this->destroySession($request);

        try {
            $auth = $this->newAuth();
            $auth->logout(url('/'));
        } catch (\Throwable $e) {
            Log::info('SAML logout request failed', ['error' => $e->getMessage()]);
        }

        return redirect('/');
    }

    public function singleLogout(Request $request): RedirectResponse
    {
        try {
            $auth = $this->newAuth();
            $auth->processSLO();
        } catch (\Throwable $e) {
            Log::info('SAML SLO failed', ['error' => $e->getMessage()]);
        }
        $this->destroySession($request);

        return redirect('/');
    }

    /**
     * Fully invalidate the authenticated session on logout so no residual
     * data (incl. CSRF token) outlives the user (OWASP ASVS V3.3, CWE-613).
     */
    private function destroySession(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function acs(Request $request): RedirectResponse
    {
        $auth = $this->newAuth();
        $requestId = session('saml_request_id');

        $auth->processResponse(is_string($requestId) ? $requestId : null);

        /** @var list<string> $errors */
        $errors = $auth->getErrors();
        if ($errors !== []) {
            Log::warning('SAML ACS errors', [
                'errors' => $errors,
                'reason' => $auth->getLastErrorReason(),
            ]);
            abort(403, 'SAML: '.implode(', ', $errors));
        }
        if (! $auth->isAuthenticated()) {
            abort(403, 'SAML: not authenticated');
        }

        /** @var array<string, list<string>> $attrs */
        $attrs = $auth->getAttributesWithFriendlyName();
        $uid = $this->firstAttr($attrs, 'uid') ?? (string) $auth->getNameId();
        $name = $this->firstAttr($attrs, 'displayName') ?? '';
        $email = $this->firstAttr($attrs, 'mail') ?? '';

        // Rotate the session ID on privilege elevation to defeat session fixation
        // (OWASP ASVS V3.2.1, CWE-384). `regenerate(true)` destroys the old session.
        $request->session()->regenerate(true);

        $request->session()->put('saml_user', [
            'uid' => $uid,
            'name' => $name,
            'email' => $email,
        ]);

        return redirect('/');
    }

    /**
     * @param array<string, list<string>> $attrs
     */
    private function firstAttr(array $attrs, string $key): ?string
    {
        if (! isset($attrs[$key]) || count($attrs[$key]) === 0) {
            return null;
        }

        return $attrs[$key][0];
    }

    private function newAuth(): OneLoginAuth
    {
        /** @var array<string, mixed> $idpCfg */
        $idpCfg = (array) config('saml2.idp', []);
        /** @var array<string, mixed> $spCfg */
        $spCfg = (array) config('saml2.sp', []);
        /** @var array<string, mixed> $securityCfg */
        $securityCfg = (array) config('saml2.security', []);

        $idpCert = self::str($idpCfg, 'x509cert');
        if ($idpCert === '') {
            // Without an IdP trust anchor, onelogin/php-saml accepts unsigned
            // assertions and the whole SSO trust model collapses. Refuse to
            // initialize rather than run insecurely.
            abort(500, 'SAML not configured: SAML2_IDP_X509CERT is missing.');
        }

        return new OneLoginAuth([
            'strict' => true,
            'debug' => (bool) config('app.debug'),
            'sp' => [
                'entityId' => self::str($spCfg, 'entityId'),
                'assertionConsumerService' => [
                    'url' => self::nestedStr($spCfg, 'assertionConsumerService', 'url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => self::nestedStr($spCfg, 'singleLogoutService', 'url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => self::str($spCfg, 'NameIDFormat', 'urn:oasis:names:tc:SAML:1.1:nameid-format:persistent'),
                'x509cert' => self::str($spCfg, 'x509cert'),
                'privateKey' => self::str($spCfg, 'privateKey'),
            ],
            'idp' => [
                'entityId' => self::str($idpCfg, 'entityId'),
                'singleSignOnService' => [
                    'url' => self::nestedStr($idpCfg, 'singleSignOnService', 'url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => self::nestedStr($idpCfg, 'singleLogoutService', 'url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $idpCert,
            ],
            'security' => [
                'wantMessagesSigned' => (bool) ($securityCfg['wantMessagesSigned'] ?? true),
                'wantAssertionsSigned' => (bool) ($securityCfg['wantAssertionsSigned'] ?? true),
                'wantAssertionsEncrypted' => (bool) ($securityCfg['wantAssertionsEncrypted'] ?? false),
                'wantNameIdEncrypted' => (bool) ($securityCfg['wantNameIdEncrypted'] ?? false),
                'authnRequestsSigned' => (bool) ($securityCfg['authnRequestsSigned'] ?? false),
                'signMetadata' => (bool) ($securityCfg['signMetadata'] ?? false),
                'rejectUnsolicitedResponsesWithInResponseTo' => (bool) ($securityCfg['rejectUnsolicitedResponsesWithInResponseTo'] ?? true),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function str(array $arr, string $key, string $default = ''): string
    {
        $value = $arr[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function nestedStr(array $arr, string $outer, string $inner): string
    {
        $sub = $arr[$outer] ?? null;
        if (! is_array($sub)) {
            return '';
        }
        $value = $sub[$inner] ?? '';

        return is_string($value) ? $value : '';
    }
}
