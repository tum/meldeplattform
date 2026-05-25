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
class DevLoginController extends Controller
{
    public function show(): View
    {
        return view('pages.dev-login', [
            'suggestedAdmins' => $this->adminUsers(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
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
            ],
        );

        // Rotate session ID on privilege elevation (OWASP ASVS V3.2.1).
        $request->session()->regenerate(true);
        Auth::login($user);

        return redirect('/');
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
