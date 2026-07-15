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
        // Pin URL generation to APP_URL. route()/url() otherwise derive the
        // host from the incoming request — which is absent in queued jobs, the
        // reports:remind scheduler and OTRS polling (so they fall back to
        // localhost), and untrustworthy behind a reverse proxy that rewrites
        // the Host header. Forcing the root URL makes every generated link —
        // in the mail digests and the in-request notifications alike — resolve
        // to the canonical host. Harmless on this single-domain deployment.
        $appUrl = Config::string('app.url', '');
        if ($appUrl !== '') {
            URL::useOrigin($appUrl);

            // Shared hosting often runs behind a TLS proxy that does not forward
            // the scheme to PHP. Force HTTPS URLs when APP_URL is https.
            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
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

        self::assertProductionInvariants(
            isConsole: $this->app->runningInConsole(),
            sessionSecure: (bool) config('session.secure', false),
            appDebug: (bool) config('app.debug', false),
            queueDefault: Config::string('queue.default', 'sync'),
        );
    }

    /**
     * Pure production-safety invariants, split out from the container/config
     * lookups above so they can be unit-tested with plain values. Throws on a
     * hard misconfiguration; logs a warning for degraded-but-functional ones.
     *
     * The HTTP-only invariants are skipped in console (Artisan commands, queue
     * workers) so a deploy pipeline — which runs config:cache / migrate with
     * whatever env is set — is never blocked by them.
     */
    private static function assertProductionInvariants(bool $isConsole, bool $sessionSecure, bool $appDebug, string $queueDefault): void
    {
        // Session cookies are only relevant for HTTP.
        if (! $isConsole && ! $sessionSecure) {
            throw new \RuntimeException(
                'SESSION_SECURE_COOKIE must be true in production to prevent session hijacking over HTTP.',
            );
        }

        // A production HTTP surface with APP_DEBUG on renders Ignition/whoops
        // error pages that leak env: APP_KEY (which HMACs report receipt codes),
        // plus DB / OTRS / SAML credentials.
        if (! $isConsole && $appDebug) {
            throw new \RuntimeException(
                'APP_DEBUG must be false in production to avoid leaking secrets (APP_KEY, DB/OTRS/SAML credentials) via error pages.',
            );
        }

        // Warn on the dangerous case, not the intended one. This deployment runs
        // no queue workers (LRZ shared hosting) and ships no jobs migration by
        // design, so `sync` is the only driver that actually delivers a
        // notification. A non-sync driver enqueues into a backend nothing ever
        // drains, silently stranding every notification — which is what a
        // server-side drift to `database` caused in June 2026.
        //
        // This previously warned on `sync` and told operators to switch to
        // `database`, i.e. it advised the change that caused that incident —
        // once per request, into an unrotated log.
        if ($queueDefault !== 'sync') {
            Log::warning(sprintf(
                'Queue driver is `%s` in production, but no worker runs here and no jobs table ships with this app — '
                .'notifications will be enqueued and never delivered. Set QUEUE_CONNECTION=sync.',
                $queueDefault,
            ));
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
