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
        // An explicit ?lang= wins over cookie and header. Crawlers send no
        // Accept-Language, so without it the German page — and its German
        // search terms — would never be indexed; the layout's hreflang links
        // point here. It is persisted like the header toggle so a visitor
        // arriving from a search result keeps that language on the next click.
        $lang = $request->query('lang');
        $fromQuery = in_array($lang, self::SUPPORTED, true);
        if (! $fromQuery) {
            $lang = $request->cookie('lang');
        }

        if (! in_array($lang, self::SUPPORTED, true)) {
            $accept = (string) $request->headers->get('Accept-Language', '');
            $lang = str_starts_with(strtolower($accept), 'de') ? 'de' : 'en';
        }

        App::setLocale($lang);
        $request->attributes->set('lang', $lang);

        $response = $next($request);

        if ($fromQuery && $request->cookie('lang') !== $lang) {
            $response->headers->setCookie(cookie(
                'lang',
                $lang,
                60 * 24 * 365,
                '/',
                null,
                $request->secure(),
                true,
            ));
        }

        $response->headers->set(
            'Content-Language',
            $lang === 'de' ? 'de-DE' : 'en-US',
        );

        return $response;
    }
}
