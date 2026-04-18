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
        $auth->login(url('/'));

        // onelogin's login() calls exit() internally after emitting the redirect,
        // so we never actually reach the line below at runtime. It is here only
        // to satisfy the return-type contract for tests and static analysis.
        return redirect('/'); // @phpstan-ignore deadCode.unreachable
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('saml_user');

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
        $request->session()->forget('saml_user');

        return redirect('/');
    }

    public function acs(Request $request): RedirectResponse
    {
        $auth = $this->newAuth();
        $auth->processResponse();

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
                'x509cert' => self::str($idpCfg, 'x509cert'),
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
