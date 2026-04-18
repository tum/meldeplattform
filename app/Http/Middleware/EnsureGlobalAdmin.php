<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnsureGlobalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array{uid?: string}|null $saml */
        $saml = $request->session()->get('saml_user');
        $uid = $saml['uid'] ?? null;

        /** @var list<string> $admins */
        $admins = array_values(array_filter(
            Config::array('meldeplattform.admin_users', []),
            'is_string',
        ));

        if ($uid === null || ! in_array($uid, $admins, true)) {
            abort(403);
        }

        return $next($request);
    }
}
