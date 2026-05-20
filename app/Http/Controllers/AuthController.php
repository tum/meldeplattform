<?php

namespace App\Http\Controllers;

use App\Auth\Oidc\OidcAuthenticationException;
use App\Auth\Oidc\OidcProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * OIDC SP (relying party) controller. Drives the Authorization-Code-with-PKCE
 * flow against TUM's IdP via the custom Socialite "oidc" provider.
 */
class AuthController extends Controller
{
    public function login(Request $request): SymfonyRedirectResponse
    {
        /** @var OidcProvider $provider */
        $provider = Socialite::driver('oidc');

        return $provider->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var OidcProvider $provider */
        $provider = Socialite::driver('oidc');

        try {
            $socUser = $provider->user();
        } catch (InvalidStateException $e) {
            Log::warning('OIDC callback rejected: state mismatch.');
            abort(403, 'OIDC: invalid state.');
        } catch (OidcAuthenticationException $e) {
            Log::warning('OIDC callback rejected', ['error' => $e->getMessage()]);
            abort(403, 'OIDC: '.$e->getMessage());
        }

        /** @var array<string, mixed> $claims */
        $claims = $socUser->getRaw();

        $uidClaim = Config::string('oidc.user_id_claim', 'preferred_username');
        $uidRaw = $claims[$uidClaim] ?? $claims['sub'] ?? null;
        $uid = is_string($uidRaw) && $uidRaw !== '' ? $uidRaw : null;
        if ($uid === null) {
            abort(403, 'OIDC: missing user identifier claim.');
        }

        $name = self::firstNonEmpty([
            isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
            self::concatNames($claims),
            isset($claims['preferred_username']) && is_string($claims['preferred_username']) ? $claims['preferred_username'] : null,
            isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null,
        ]) ?? '';

        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';

        // Rotate the session ID on privilege elevation to defeat session fixation
        // (OWASP ASVS V3.2.1, CWE-384). `regenerate(true)` destroys the old session.
        $request->session()->regenerate(true);

        $request->session()->put('auth_user', [
            'uid' => $uid,
            'name' => $name,
            'email' => $email,
        ]);

        // Stash id_token for RP-initiated logout (`id_token_hint`).
        $idToken = isset($claims['_id_token']) && is_string($claims['_id_token']) ? $claims['_id_token'] : '';
        if ($idToken !== '') {
            $request->session()->put('oidc.id_token', $idToken);
        }

        return redirect('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        $idTokenRaw = $request->session()->get('oidc.id_token', '');
        $idToken = is_string($idTokenRaw) ? $idTokenRaw : '';

        // Fully invalidate the authenticated session on logout so no residual
        // data (incl. CSRF token) outlives the user (OWASP ASVS V3.3, CWE-613).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        try {
            /** @var OidcProvider $provider */
            $provider = Socialite::driver('oidc');
            $endSession = $provider->buildEndSessionUrl($idToken !== '' ? $idToken : null);
            if ($endSession !== null) {
                return redirect()->away($endSession);
            }
        } catch (\Throwable $e) {
            // If discovery is unreachable on logout, fall through to local
            // logout — better to drop the session than to leave the user
            // staring at an error page.
            Log::info('OIDC end_session_endpoint unavailable on logout', ['error' => $e->getMessage()]);
        }

        return redirect('/');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function concatNames(array $claims): ?string
    {
        $given = isset($claims['given_name']) && is_string($claims['given_name']) ? $claims['given_name'] : '';
        $family = isset($claims['family_name']) && is_string($claims['family_name']) ? $claims['family_name'] : '';
        $combined = trim($given.' '.$family);

        return $combined !== '' ? $combined : null;
    }

    /**
     * @param list<string|null> $candidates
     */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }

        return null;
    }
}
