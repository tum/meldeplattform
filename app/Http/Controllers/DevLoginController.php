<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;

/**
 * Dev-only login bypass. Only wired up from routes/web.php when
 * `APP_ENV !== 'production'` so the SAML flow stays mandatory in prod.
 */
class DevLoginController
{
    /**
     * Defence in depth. This controller logs in as *any* UID from the request
     * body — including `globaladmin` — with no credential of any kind, so the
     * only thing standing between it and a total auth bypass was route
     * registration. Conditional route registration is defeated by a route:cache
     * artifact built under a non-production APP_ENV and then deployed, which is
     * a live risk here: production is shared hosting where artisan is run by
     * hand. Re-check the same two conditions at the point of use, where no
     * cached artifact can skip them.
     */
    private function assertEnabled(): void
    {
        abort_if(
            app()->environment('production') || ! (bool) config('meldeplattform.dev_login_enabled', false),
            404,
        );
    }

    public function show(): View
    {
        $this->assertEnabled();

        return view('pages.dev-login', [
            'suggestedAdmins' => $this->adminUsers(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $this->assertEnabled();

        $uid = trim($request->string('uid', '')->toString());
        if ($uid === '') {
            return back()->withErrors(['uid' => 'UID required']);
        }

        $name = trim($request->string('name', '')->toString());
        $email = trim($request->string('email', '')->toString());

        $user = User::updateOrCreate(
            ['uid' => $uid],
            [
                'name' => $name !== '' ? $name : $uid,
                'email' => $email !== '' ? $email : $uid.'@example.com',
                'last_login_at' => now(),
            ],
        );

        // Rotate session ID on privilege elevation (OWASP ASVS V3.2.1).
        $request->session()->regenerate(true);
        Auth::login($user);

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * @return list<string>
     */
    private function adminUsers(): array
    {
        /** @var list<string> $admins */
        $admins = array_values(array_filter(
            Config::array('meldeplattform.admin_users', []),
            'is_string',
        ));

        return $admins;
    }
}
