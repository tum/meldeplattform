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

    public function setLang(Request $request): RedirectResponse
    {
        $lang = $request->string('lang', 'en')->toString();
        if (! in_array($lang, ['de', 'en'], true)) {
            $lang = 'en';
        }

        // Tie the Secure flag to the actual request scheme rather than the
        // environment: a misconfigured prod box reached over HTTP would
        // otherwise silently drop the cookie the browser refuses to resend.
        return redirect('/')->withCookie(cookie(
            'lang',
            $lang,
            60 * 24 * 365,
            '/',
            null,
            $request->secure(),
            true,
        ));
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
}
