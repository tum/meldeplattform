<?php

namespace App\Http\Middleware;

use App\Models\Topic;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareViewData
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = App::getLocale();

        /** @var array{uid?: string, name?: string, email?: string}|null $auth */
        $auth = $request->session()->get('auth_user');
        $uid = $auth['uid'] ?? null;
        $name = $auth['name'] ?? null;
        $email = $auth['email'] ?? null;

        /** @var list<string> $admins */
        $admins = array_values(array_filter(
            Config::array('meldeplattform.admin_users', []),
            'is_string',
        ));
        $isAdmin = $uid !== null && in_array($uid, $admins, true);

        View::share([
            'lang' => $lang,
            'topicsAll' => Topic::with('admins')->get(),
            'authUid' => $uid,
            'authName' => $name,
            'authEmail' => $email,
            'authLoggedIn' => $uid !== null,
            'isGlobalAdmin' => $isAdmin,
            'appTitle' => Config::string('meldeplattform.title.'.$lang, ''),
            'appSubtitle' => Config::string('meldeplattform.subtitle.'.$lang, ''),
        ]);

        return $next($request);
    }
}
