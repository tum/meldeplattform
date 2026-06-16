<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth as OneLoginAuth;

/**
 * Thin SAML SP implementation backed directly by onelogin/php-saml. Mirrors the
 * behaviour of the Go /saml/{metadata,out,slo} + /shib handlers.
 */
class SamlController
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
        // Require an IdP-issued SAMLRequest/SAMLResponse on the SLO endpoint.
        // Without this guard, a cross-origin GET (e.g. <img src="/saml/slo">)
        // would force the victim to log out, since /saml/slo is CSRF-exempt.
        if (! $request->has('SAMLRequest') && ! $request->has('SAMLResponse')) {
            abort(400, 'SAML SLO requires SAMLRequest or SAMLResponse');
        }

        $redirectUrl = null;
        $sloSucceeded = false;
        try {
            $auth = $this->newAuth();
            // keepLocalSession=true: we destroy Laravel's session ourselves below.
            // stay=true: return the IdP response URL instead of exit()'ing.
            $redirectUrl = $auth->processSLO(true, null, false, null, true);
            /** @var list<string> $errors */
            $errors = $auth->getErrors();
            if ($errors === []) {
                $sloSucceeded = true;
            } else {
                $this->logSloFailure($auth, $errors);
            }
        } catch (\Throwable $e) {
            Log::info('SAML SLO failed', ['error' => $e->getMessage()]);
        }

        if ($sloSucceeded) {
            $this->destroySession($request);
        }

        return is_string($redirectUrl) && $redirectUrl !== ''
            ? redirect()->away($redirectUrl)
            : redirect('/');
    }

    /**
     * Surface a failing SLO with enough context to debug. Lifts the
     * <StatusCode> Value attribute and any <StatusMessage> out of the
     * IdP's LogoutResponse XML so the log isn't just
     * `errors:[logout_not_success], reason:null`.
     *
     * The bare "logout_not_success" with no extra signal is benign: it
     * means the IdP rejected the SLO (often because the user's IdP
     * session was already gone), but the local SP session was already
     * destroyed in /saml/logout before we ever got here. Downgrade
     * those to info() so they don't page the on-call. Anything richer
     * (a sub-status, a reason, multiple errors) still warns.
     *
     * @param list<string> $errors
     */
    private function logSloFailure(OneLoginAuth $auth, array $errors): void
    {
        $reason = $auth->getLastErrorReason();
        $context = ['errors' => $errors, 'reason' => $reason];

        $xml = (string) ($auth->getLastResponseXML() ?? '');
        if ($xml !== '') {
            [$statusCode, $statusMessage] = $this->parseLogoutResponseStatus($xml);
            if ($statusCode !== null) {
                $context['status_code'] = $statusCode;
            }
            if ($statusMessage !== null) {
                $context['status_message'] = $statusMessage;
            }
        }

        $benign = $errors === ['logout_not_success']
            && ($reason === null || $reason === '')
            && ! isset($context['status_message']);

        if ($benign) {
            Log::info('SAML SLO returned non-success (local session already destroyed)', $context);
        } else {
            Log::warning('SAML SLO validation errors', $context);
        }
    }

    /**
     * Extract the top-level StatusCode @Value and any StatusMessage from
     * a SAML LogoutResponse XML payload. Returns [null, null] on parse
     * failure so the caller falls back to its existing log shape.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseLogoutResponseStatus(string $xml): array
    {
        $previousInternalErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument;
            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                return [null, null];
            }
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

            $codeList = $xpath->query('/samlp:LogoutResponse/samlp:Status/samlp:StatusCode/@Value');
            $codeNode = $codeList !== false ? $codeList->item(0) : null;
            $code = $codeNode !== null ? (string) $codeNode->nodeValue : null;

            $msgList = $xpath->query('/samlp:LogoutResponse/samlp:Status/samlp:StatusMessage');
            $msgNode = $msgList !== false ? $msgList->item(0) : null;
            if ($msgNode === null) {
                return [$code, null];
            }
            $message = trim((string) $msgNode->nodeValue);

            return [$code, $message === '' ? null : $message];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /**
     * Fully invalidate the authenticated session on logout so no residual
     * data (incl. CSRF token) outlives the user (OWASP ASVS V3.3, CWE-613).
     */
    private function destroySession(Request $request): void
    {
        Auth::guard('web')->logout();
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

        $user = User::updateOrCreate(
            ['uid' => $uid],
            ['name' => $name !== '' ? $name : null, 'email' => $email !== '' ? $email : null],
        );

        // Rotate the session ID on privilege elevation to defeat session fixation
        // (OWASP ASVS V3.2.1, CWE-384). `regenerate(true)` destroys the old session.
        $request->session()->regenerate(true);
        Auth::login($user);

        return redirect()->intended('/');
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
        $idpCert = Config::string('saml2.idp.x509cert', '');
        if ($idpCert === '') {
            // Without an IdP trust anchor, onelogin/php-saml accepts unsigned
            // assertions and the whole SSO trust model collapses. Refuse to
            // initialize rather than run insecurely.
            abort(500, 'SAML not configured: SAML2_IDP_X509CERT is missing.');
        }

        return new OneLoginAuth([
            'strict' => true,
            'debug' => Config::boolean('app.debug', false),
            'sp' => [
                'entityId' => Config::string('saml2.sp.entityId', ''),
                'assertionConsumerService' => [
                    'url' => Config::string('saml2.sp.assertionConsumerService.url', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => Config::string('saml2.sp.singleLogoutService.url', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => Config::string('saml2.sp.NameIDFormat', 'urn:oasis:names:tc:SAML:1.1:nameid-format:persistent'),
                'x509cert' => Config::string('saml2.sp.x509cert', ''),
                'privateKey' => Config::string('saml2.sp.privateKey', ''),
            ],
            'idp' => [
                'entityId' => Config::string('saml2.idp.entityId', ''),
                'singleSignOnService' => [
                    'url' => Config::string('saml2.idp.singleSignOnService.url', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => Config::string('saml2.idp.singleLogoutService.url', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $idpCert,
            ],
            'security' => [
                'wantMessagesSigned' => Config::boolean('saml2.security.wantMessagesSigned', true),
                'wantAssertionsSigned' => Config::boolean('saml2.security.wantAssertionsSigned', true),
                'wantAssertionsEncrypted' => Config::boolean('saml2.security.wantAssertionsEncrypted', false),
                'wantNameIdEncrypted' => Config::boolean('saml2.security.wantNameIdEncrypted', false),
                'authnRequestsSigned' => Config::boolean('saml2.security.authnRequestsSigned', false),
                'signMetadata' => Config::boolean('saml2.security.signMetadata', false),
                'rejectUnsolicitedResponsesWithInResponseTo' => Config::boolean('saml2.security.rejectUnsolicitedResponsesWithInResponseTo', true),
            ],
        ]);
    }
}
