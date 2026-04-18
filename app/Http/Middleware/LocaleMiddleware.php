<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class LocaleMiddleware
{
    private const SUPPORTED = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->cookie('lang');

        if (! in_array($lang, self::SUPPORTED, true)) {
            $accept = (string) $request->headers->get('Accept-Language', '');
            $lang = str_starts_with(strtolower($accept), 'de') ? 'de' : 'en';
        }

        App::setLocale($lang);
        $request->attributes->set('lang', $lang);

        $response = $next($request);

        $response->headers->set(
            'Content-Language',
            $lang === 'de' ? 'de-DE' : 'en-US',
        );

        return $response;
    }
}
