<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Services\SystemStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatsPageTest extends TestCase
{
    use RefreshDatabase;

    private function topic(string $name = 'T'): Topic
    {
        return Topic::create(['name_de' => $name, 'name_en' => $name, 'summary_de' => '', 'summary_en' => '']);
    }

    public function test_only_global_admins_may_open_the_page(): void
    {
        $topic = $this->topic();
        $this->get('/stats')->assertRedirect();
        $this->actingAsUser('regular')->get('/stats')->assertStatus(403);
        Admin::create(['user_id' => 'ta'])->topics()->attach($topic);
        $this->actingAsUser('ta')->get('/stats')->assertStatus(403);
        $this->actingAsGlobalAdmin()->get('/stats')->assertOk();
    }

    public function test_page_renders_every_section_and_no_report_content(): void
    {
        $topic = $this->topic('Compliance');
        $report = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open, 'creator' => 'someone@example.test']);
        Message::create(['report_id' => $report->id, 'content' => 'SECRET BODY TEXT', 'is_admin' => false]);

        $html = (string) $this->actingAsGlobalAdmin()->get('/stats')->assertOk()->getContent();

        foreach (['Reports per month', 'Communication', 'People &amp; access', 'Retention', 'System', 'Checks', 'Compliance'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Counts and configuration only.
        $this->assertStringNotContainsString('SECRET BODY TEXT', $html);
        $this->assertStringNotContainsString('someone@example.test', $html);
        // Header link only for global admins.
        $this->assertStringContainsString('aria-current="page">Statistics</a>', $html);
    }

    public function test_report_figures_are_computed_from_the_data(): void
    {
        Carbon::setTestNow('2026-09-11 12:00:00');
        $topic = $this->topic('Alpha');
        $other = $this->topic('Beta');

        // created_at is not mass-assignable; forceFill sets the backdated stamps.
        $make = static function (Topic $t, int $daysAgo, array $extra = []): Report {
            $r = Report::create(['topic_id' => $t->id]);
            $r->forceFill(['created_at' => now()->subDays($daysAgo)] + $extra)->save();

            return $r;
        };

        // Acknowledged after 2 days (on time), closed after 10 days.
        $make($topic, 20, ['acknowledged_at' => now()->subDays(18), 'state' => ReportState::Done, 'closed_at' => now()->subDays(10)]);
        // Acknowledged after 10 days (late), still in progress.
        $make($topic, 30, ['acknowledged_at' => now()->subDays(20), 'state' => ReportState::InProgress]);
        // Fresh, unacknowledged: not yet eligible for the adherence ratio.
        $make($other, 2);
        // Old and open → acknowledgement overdue.
        $make($other, 40);
        // Spam is excluded from adherence and counted separately.
        $make($other, 5, ['state' => ReportState::Spam, 'closed_at' => now()->subDays(4)]);
        // Last year, outside the 12-month trend window.
        $make($topic, 420);

        $r = (new SystemStats)->reports();

        $this->assertSame(6, $r['total']);
        $this->assertSame(['open' => 3, 'in_progress' => 1, 'done' => 1, 'spam' => 1], $r['states']);
        $this->assertSame(4, $r['active']);
        // The 40-day and the 420-day open reports; the latter is past feedback too.
        $this->assertSame(2, $r['ack_overdue']);
        $this->assertSame(1, $r['feedback_overdue']);
        $this->assertSame(4, $r['last_30']);   // 20 d, 2 d, 5 d (spam) and the 30 d one exactly on the edge
        $months = $r['months'];
        $this->assertIsArray($months);
        $this->assertCount(12, $months);
        $this->assertSame(5, array_sum(array_column($months, 'count')));
        // Median ack: 48 h and 240 h → 144 h.
        $this->assertEqualsWithDelta(144.0, $r['median_ack_hours'], 0.01);
        $this->assertEqualsWithDelta(10.0, $r['median_close_days'], 0.01);
        // Eligible for ack adherence: onTime, late, the 40-day-old open one → 1 of 3 on time.
        $this->assertSame(3, $r['ack_eligible']);
        $this->assertSame(1, $r['ack_on_time']);
        $byTopic = $r['by_topic'];
        $this->assertIsArray($byTopic);
        $top = $byTopic[0];
        $this->assertIsArray($top);
        $this->assertSame('Alpha', $top['name_en']);
        $this->assertSame(3, $top['count']);
        $this->assertSame(2, $top['open']); // in progress + the old open one

        Carbon::setTestNow();
    }

    public function test_people_and_retention_figures(): void
    {
        config(['meldeplattform.inactive_user_days' => 365, 'meldeplattform.dormant_admin_days' => 365, 'meldeplattform.audit_retention_days' => 1095]);
        $topic = $this->topic();
        User::create(['uid' => 'globaladmin', 'name' => 'G', 'email' => 'g@x', 'last_login_at' => now()]);
        User::create(['uid' => 'ta', 'name' => 'T', 'email' => 't@x', 'last_login_at' => now()->subDays(400)]);
        Admin::create(['user_id' => 'ta'])->topics()->attach($topic);
        Admin::create(['user_id' => 'pending'])->topics()->attach($topic);
        User::create(['uid' => 'stale', 'name' => 'S', 'email' => 's@x', 'last_login_at' => now()->subDays(400)]);
        User::create(['uid' => 'fresh', 'name' => 'F', 'email' => 'f@x', 'last_login_at' => now()->subDays(3)]);

        $stats = new SystemStats;
        $people = $stats->people();
        $this->assertSame(['global' => 1, 'topic' => 1, 'pending' => 1, 'regular' => 2], array_intersect_key($people, array_flip(['global', 'topic', 'pending', 'regular'])));
        $this->assertSame(2, $people['logins_30']);

        $retention = array_column($stats->retention(), null, 'key');
        $this->assertSame(1, $retention['users']['due']);
        $this->assertSame(1, $retention['admins']['due']); // the dormant topic admin; the pending row is fresh
        $this->assertSame(0, $retention['audit']['due']);
        $this->assertNull($retention['users']['last_run']);

        AuditLog::record('users.pruned', null, ['count' => 1]);
        $this->assertNotNull(array_column($stats->retention(), null, 'key')['users']['last_run']);
    }

    public function test_page_survives_unset_env_backed_config(): void
    {
        // CI has no .env: env-backed keys are present but null. The page must
        // not 500 on them (Config::boolean() would throw on null).
        config(['session.secure' => null, 'mail.mailers.smtp.host' => null, 'app.key' => null]);
        $this->topic();

        $this->actingAsGlobalAdmin()->get('/stats')->assertOk();
    }

    public function test_scheduler_heartbeat_is_read_from_cache(): void
    {
        Cache::forget('scheduler.heartbeat');
        $this->assertNull(SystemStats::schedulerHeartbeat());

        Cache::forever('scheduler.heartbeat', '2026-09-11T10:00:00+02:00');
        $heartbeat = SystemStats::schedulerHeartbeat();
        $this->assertInstanceOf(Carbon::class, $heartbeat);
        $this->assertSame('2026-09-11 08:00:00', $heartbeat->utc()->toDateTimeString());
    }

    public function test_helpers(): void
    {
        $this->assertSame('1.5 KB', SystemStats::bytes(1536));
        $this->assertSame('1,5 KB', SystemStats::bytes(1536, 'de'));
        $this->assertSame('—', SystemStats::bytes(null));
        $this->assertSame(128 * 1024 * 1024, SystemStats::iniBytes('128M'));
        $this->assertNull(SystemStats::iniBytes('-1'));
        $this->assertSame(2.0, SystemStats::median([3.0, 1.0, 2.0]));
        $this->assertSame(2.5, SystemStats::median([1.0, 4.0, 2.0, 3.0]));
        $this->assertNull(SystemStats::median([]));
    }
}
