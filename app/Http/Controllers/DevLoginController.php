<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $request->session()->put('saml_user', [
            'uid' => $uid,
            'name' => $name !== '' ? $name : $uid,
            'email' => $email !== '' ? $email : $uid.'@example.com',
        ]);

        return redirect('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('saml_user');

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
