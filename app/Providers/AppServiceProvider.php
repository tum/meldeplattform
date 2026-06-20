<?php

namespace App\Providers;

use App\Services\MessengerDispatcher;
use App\View\Composers\AppLayoutComposer;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->assertProductionConfig();
        $this->configureRateLimiting();

        // Preserve the legacy top-level JSON shape consumed by the admin
        // editor. Without this, JsonResource responses would be wrapped in
        // a `{ "data": … }` envelope, breaking the existing JS client.
        JsonResource::withoutWrapping();

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

    /**
     * Enforce safety invariants that must hold in production. Throws on a hard
     * misconfiguration (e.g. insecure session cookie); logs a warning for
     * degraded-but-functional settings (e.g. synchronous queue).
     */
    private function assertProductionConfig(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        // Session cookies are only relevant for HTTP — skip when running Artisan
        // commands or queue workers so they don't fail the deploy pipeline.
        if (! $this->app->runningInConsole() && ! (bool) config('session.secure', false)) {
            throw new \RuntimeException(
                'SESSION_SECURE_COOKIE must be true in production to prevent session hijacking over HTTP.',
            );
        }

        if (config('queue.default', 'sync') === 'sync') {
            Log::warning('Queue driver is `sync` in production — mail and webhook dispatch will block requests. Set QUEUE_CONNECTION=database or redis.');
        }
    }

    /**
     * Named rate limiters used by routes/web.php. Centralising the throttle
     * policy keeps tuning in one place and lets logged-in admins key by user
     * ID instead of sharing an IP bucket with anonymous reporters.
     */
    private function configureRateLimiting(): void
    {
        $perIp = static fn (int $perMinute): Closure => static fn (Request $request): Limit => Limit::perMinute($perMinute)->by((string) $request->ip());

        $perUserOrIp = static fn (int $perMinute): Closure => static function (Request $request) use ($perMinute): Limit {
            $key = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($perMinute)->by(
                is_scalar($key) ? 'user:'.$key : 'ip:'.((string) $request->ip()),
            );
        };

        // /submit — anonymous only; per-IP keeps storage-exhaustion abuse local.
        RateLimiter::for('submit', $perIp(10));

        // /report — admins are logged in, reporters aren't; differentiate keys.
        RateLimiter::for('report', $perUserOrIp(60));

        // /file/{name} — same mix of authenticated admins and anon reporters.
        RateLimiter::for('file-download', $perUserOrIp(60));

        // /dev/login — pre-auth bypass page; always anonymous.
        RateLimiter::for('dev-login', $perIp(5));

        // /saml/out and /shib — pre-auth flow start/ACS; always anonymous.
        RateLimiter::for('saml', $perIp(20));

        // Admin write endpoints (status changes, bulk updates, acknowledge).
        // Keyed by authenticated user so one user can't DoS the audit log.
        RateLimiter::for('admin-write', $perUserOrIp(120));
    }
}
