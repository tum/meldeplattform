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

        View::share([
            'lang' => $lang,
            'topicsAll' => Topic::with('admins')->get(),
            'appTitle' => Config::string('meldeplattform.title.'.$lang, ''),
            'appSubtitle' => Config::string('meldeplattform.subtitle.'.$lang, ''),
        ]);

        return $next($request);
    }
}
