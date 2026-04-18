<?php

namespace App\Http\Middleware;

use App\Models\Topic;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class EnsureTopicAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array{uid?: string}|null $saml */
        $saml = $request->session()->get('saml_user');
        $uid = $saml['uid'] ?? null;

        if ($uid === null) {
            abort(401);
        }

        /** @var list<string> $admins */
        $admins = array_values(array_filter(
            Config::array('meldeplattform.admin_users', []),
            'is_string',
        ));
        if (in_array($uid, $admins, true)) {
            return $next($request);
        }

        $rawId = $request->route('topicID');
        $topicId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($topicId === 0) {
            abort(403);
        }

        $topic = Topic::with('admins')->find($topicId);
        if ($topic === null) {
            abort(404);
        }

        if (! $topic->isAdmin($uid)) {
            abort(403);
        }

        return $next($request);
    }
}
