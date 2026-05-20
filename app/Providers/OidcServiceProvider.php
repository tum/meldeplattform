<?php

namespace App\Providers;

use App\Auth\Oidc\OidcDiscovery;
use App\Auth\Oidc\OidcJwks;
use App\Auth\Oidc\OidcProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class OidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OidcDiscovery::class, function ($app): OidcDiscovery {
            return new OidcDiscovery($app->make(CacheRepository::class), self::oidcConfig());
        });

        $this->app->singleton(OidcJwks::class, function ($app): OidcJwks {
            return new OidcJwks(
                $app->make(OidcDiscovery::class),
                $app->make(CacheRepository::class),
                self::oidcConfig(),
            );
        });
    }

    public function boot(): void
    {
        // Note: Socialite's Manager::extend rebinds the closure scope, so
        // `self::` would resolve to the Manager. Reference the class name
        // explicitly via a callable to keep the static call intact.
        Socialite::extend('oidc', \Closure::fromCallable([self::class, 'createDriver']));
    }

    public static function createDriver(Container $app): OidcProvider
    {
        return new OidcProvider(
            $app->make(Request::class),
            Config::string('oidc.client_id', ''),
            Config::string('oidc.client_secret', ''),
            Config::string('oidc.redirect_uri', ''),
            $app->make(OidcDiscovery::class),
            $app->make(OidcJwks::class),
            self::oidcConfig(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function oidcConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('oidc', []);

        return $config;
    }
}
