<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Http\Controllers\DevLoginController;
use App\Models\Report;
use App\Models\Topic;
use App\Services\Messengers\WebhookMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Smaller hardening items from the full-project review.
 */
class HardeningFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_login_refuses_to_run_in_production_even_if_reached(): void
    {
        // The routes are only registered outside production, so this controller
        // was protected by route registration alone. That is defeated by a
        // route:cache artifact built under a non-production APP_ENV and then
        // deployed — a live risk when prod is shared hosting and artisan is run
        // by hand. Calling it directly stands in for that cached-route path.
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['meldeplattform.dev_login_enabled' => true]);

        $controller = new DevLoginController;

        try {
            $controller->login(Request::create('/dev/login', 'POST', ['uid' => 'globaladmin']));
            $this->fail('dev login logged in as globaladmin in production');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertGuest();
    }

    public function test_dev_login_refuses_when_the_config_flag_is_off(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config(['meldeplattform.dev_login_enabled' => false]);

        try {
            (new DevLoginController)->login(Request::create('/dev/login', 'POST', ['uid' => 'someone']));
            $this->fail('dev login ran with the feature flag off');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertGuest();
    }

    public function test_webhook_does_not_follow_a_redirect_to_an_internal_host(): void
    {
        // The https check only constrains the first hop. Guzzle follows
        // redirects by default (protocols http+https, strict=false), and a 307
        // preserves method and body — so a topic-admin-configured target could
        // bounce the POST to an internal cleartext service.
        Http::fake([
            'hook.example/*' => Http::response('', 307, ['Location' => 'http://127.0.0.1:6379/']),
            '127.0.0.1:*' => Http::response('should never be reached', 200),
        ]);

        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = $report->messages()->create(['content' => 'body', 'is_admin' => false]);

        (new WebhookMessenger('https://hook.example/hook'))->send('t', $message, 'https://app.test/reports/1/1');

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_dashboard_orders_reports_by_a_unique_tiebreaker(): void
    {
        // paginate() uses OFFSET and updated_at is second-precision, so without
        // a tiebreaker a tied report can appear on two pages or on none —
        // hiding it from its admin entirely. exportCsv() already does this.
        $topic = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        Report::factory()->count(3)->create(['topic_id' => $topic->id, 'state' => ReportState::Open]);

        DB::enableQueryLog();
        $this->actingAsGlobalAdmin()->get('/dashboard')->assertOk();

        /** @var list<string> $paged */
        $paged = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(static fn (mixed $q): bool => is_string($q)
                && str_contains($q, 'from "reports"')
                && str_contains($q, 'limit'))
            ->values()
            ->all();
        DB::disableQueryLog();

        $this->assertNotEmpty($paged, 'the dashboard did not page over reports');
        foreach ($paged as $query) {
            $this->assertStringContainsString('order by "updated_at" desc, "id" desc', $query);
        }
    }
}
