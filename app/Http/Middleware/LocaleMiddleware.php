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
        // An explicit ?lang= wins over cookie and header for this request
        // only (nothing is persisted). Crawlers send no Accept-Language, so
        // without it the German page — and its German search terms — would
        // never be indexed; the layout's hreflang links point here.
        $lang = $request->query('lang');
        if (! in_array($lang, self::SUPPORTED, true)) {
            $lang = $request->cookie('lang');
        }

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
