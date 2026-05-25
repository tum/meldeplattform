<?php

namespace App\Providers;

use App\Services\MessengerDispatcher;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessengerDispatcher::class);
    }

    public function boot(): void
    {
        // Shared hosting often runs behind a TLS proxy that does not forward
        // the scheme to PHP. Force HTTPS URLs when APP_URL is https.
        if (str_starts_with(Config::string('app.url', ''), 'https://')) {
            URL::forceScheme('https');
        }

        // Locale-derived branding strings are cheap config lookups and need
        // to be available inside child views' @section('title', ...)
        // expressions (which run before the parent layout), so share them
        // globally rather than via a composer.
        View::composer('*', static function ($view): void {
            $lang = app()->getLocale();
            $view->with([
                'lang' => $lang,
                'appTitle' => Config::string('meldeplattform.title.'.$lang, ''),
                'appSubtitle' => Config::string('meldeplattform.subtitle.'.$lang, ''),
            ]);
        });

        // Only the home page needs the topic list — keep the query out of
        // every other view render.
        View::composer('pages.index', AppLayoutComposer::class);
    }
}
