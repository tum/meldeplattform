<?php

namespace App\Http\Controllers;

use App\Support\Markdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;

class HomeController
{
    public function index(): View
    {
        return view('pages.index');
    }

    public function imprint(): View
    {
        return view('pages.info', [
            'content' => Markdown::renderOperatorContent($this->operatorPage('imprint', '# Impressum')),
            'pageTitle' => __('imprint'),
        ]);
    }

    public function privacy(): View
    {
        return view('pages.info', [
            'content' => Markdown::renderOperatorContent($this->operatorPage('privacy', '# Datenschutz')),
            'pageTitle' => __('privacy'),
        ]);
    }

    public function process(): View
    {
        return view('pages.process', [
            'content' => Markdown::renderOperatorContent($this->localizedPage('process')),
            'ackDays' => Config::integer('meldeplattform.acknowledgement_deadline_days'),
            'feedbackDays' => Config::integer('meldeplattform.feedback_deadline_days'),
            'metaDescription' => __('process_meta_description'),
        ]);
    }

    public function support(): View
    {
        return view('pages.info', [
            'content' => Markdown::renderOperatorContent($this->localizedPage('support')),
            'pageTitle' => __('support_title'),
            'metaDescription' => __('support_meta_description'),
        ]);
    }

    public function setLang(Request $request): RedirectResponse
    {
        $lang = $request->string('lang', 'en')->toString();
        if (! in_array($lang, ['de', 'en'], true)) {
            $lang = 'en';
        }

        // Tie the Secure flag to the actual request scheme rather than the
        // environment: a misconfigured prod box reached over HTTP would
        // otherwise silently drop the cookie the browser refuses to resend.
        return redirect($this->resolveReturnUrl($request))->withCookie(cookie(
            'lang',
            $lang,
            60 * 24 * 365,
            '/',
            null,
            $request->secure(),
            true,
        ));
    }

    /**
     * Return the user to the page they switched language on — but only when
     * the Referer points back to the same origin. Anything else (off-site
     * referer, missing header, malformed URL) falls back to `/` so we don't
     * turn `/setLang` into an open-redirect oracle.
     */
    private function resolveReturnUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return '/';
        }

        $parsed = parse_url($referer);
        if (! is_array($parsed)) {
            return '/';
        }

        // Match origin = scheme + host + explicit port. `getHttpHost()` is
        // already "host[:port]"; rebuilding the referer's host:port has to
        // do the same join because parse_url splits port off into its own key.
        $refererHostPort = isset($parsed['host'])
            ? $parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '')
            : '';

        $sameOrigin = $refererHostPort === $request->getHttpHost()
            && ($parsed['scheme'] ?? null) === $request->getScheme();
        if (! $sameOrigin) {
            return '/';
        }

        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        // Only ever hand back a plain, single-slash-rooted path. A referer of
        // `https://host//evil.com` parses as this origin with the path
        // `//evil.com`, and Laravel's url()->to() treats a leading `//` as an
        // already-complete protocol-relative URL and emits it verbatim — so the
        // same-origin check above would be passed while the redirect landed on
        // another site. Backslashes get the same treatment because browsers
        // normalise them to `/`.
        if (! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/\\')) {
            return '/';
        }

        // Loop guard: bouncing /setLang back to itself would strip the
        // user's actual location and re-enter the controller.
        if (rtrim($path, '/') === '/setLang') {
            return '/';
        }

        return $path.$query;
    }

    private function operatorPage(string $name, string $fallback): string
    {
        $configured = Config::string('meldeplattform.'.$name, '');
        if ($configured !== '') {
            return $configured;
        }

        $file = Config::string('meldeplattform.'.$name.'_file');

        return $file !== '' && is_file($file)
            ? (string) file_get_contents($file)
            : $fallback;
    }

    /**
     * Reporter-facing explanatory pages ship one Markdown file per locale
     * (resources/markdown/{name}.{lang}.md); German is the fallback.
     */
    private function localizedPage(string $name): string
    {
        foreach ([app()->getLocale(), 'de'] as $lang) {
            $file = resource_path("markdown/{$name}.{$lang}.md");
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }
        }

        return '';
    }
}
