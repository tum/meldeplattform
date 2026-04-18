<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Shared hosting often runs behind a TLS proxy that does not forward
        // the scheme to PHP. Force HTTPS URLs when APP_URL is https.
        if (str_starts_with(Config::string('app.url', ''), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
