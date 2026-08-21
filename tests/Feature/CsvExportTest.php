<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_auth(): void
    {
        $this->get('/dashboard/export')->assertRedirect('/dev/login');
    }

    /**
     * The export pages with chunk(), which uses OFFSET. Ordering by updated_at
     * alone is not a total order, so reports sharing a timestamp have no stable
     * position between pages and can be exported twice or skipped — an audit
     * export that silently drops rows is worse than one that fails loudly.
     */
    public function test_export_emits_every_report_exactly_once_when_updated_at_ties(): void
    {
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);

        // More than one chunk (200), all sharing an updated_at, so the tie spans
        // a page boundary.
        Report::factory()->count(250)->create(['topic_id' => $topic->id, 'state' => ReportState::Open]);
        Report::query()->toBase()->update([
            'created_at' => '2026-01-01 12:00:00',
            'updated_at' => '2026-01-01 12:00:00',
        ]);

        $csv = $this->actingAsGlobalAdmin()
            ->get('/dashboard/export?filters=1&hide_closed=0&hide_spam=0')
            ->assertOk()
            ->streamedContent();

        $lines = explode("\n", trim($csv));
        array_shift($lines); // header
        $exported = array_map(static fn (string $line): int => (int) strtok($line, ','), $lines);

        $this->assertEqualsCanonicalizing(
            Report::pluck('id')->all(),
            $exported,
            'the export skipped or duplicated reports with a tied updated_at',
        );
    }

    /**
     * The invariant above cannot fail on demand: whether tied rows actually get
     * skipped depends on the engine's chosen plan, and SQLite happens to page
     * them stably. So pin the property that makes it safe on any engine —
     * every page of the export is drawn in a total order.
     */
    public function test_export_orders_by_a_unique_tiebreaker(): void
    {
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        Report::factory()->count(3)->create(['topic_id' => $topic->id, 'state' => ReportState::Open]);

        DB::enableQueryLog();
        $this->actingAsGlobalAdmin()
            ->get('/dashboard/export?filters=1&hide_closed=0&hide_spam=0')
            ->assertOk()
            ->streamedContent();

        $paged = $this->pagedReportQueries();
        DB::disableQueryLog();

        $this->assertNotEmpty($paged, 'the export did not page over reports');
        foreach ($paged as $query) {
            $this->assertStringContainsString(
                'order by updated_at desc, id desc',
                $query,
                'updated_at alone is not a total order, so OFFSET paging can skip or repeat rows',
            );
        }
    }

    public function test_global_admin_exports_manageable_reports_as_csv(): void
    {
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open]);

        $response = $this->actingAsGlobalAdmin()->get('/dashboard/export?filters=1&hide_closed=0&hide_spam=0');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('ID,Topic,State', $csv);
        $this->assertStringContainsString((string) $report->id, $csv);
    }

    public function test_export_is_scoped_to_manageable_topics(): void
    {
        $mine = Topic::create(['name_de' => 'Mine', 'name_en' => 'Mine', 'summary_de' => '', 'summary_en' => '']);
        $other = Topic::create(['name_de' => 'Other', 'name_en' => 'Other', 'summary_de' => '', 'summary_en' => '']);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $mine->admins()->attach($admin);

        $mineReport = Report::create(['topic_id' => $mine->id, 'state' => ReportState::Open]);
        Report::create(['topic_id' => $other->id, 'state' => ReportState::Open]);

        $csv = $this->actingAsUser('topicadmin')
            ->get('/dashboard/export?filters=1&hide_closed=0&hide_spam=0')
            ->assertOk()
            ->streamedContent();

        // Only the manageable topic's rows are present; the other team's topic
        // name must not leak into the export.
        $this->assertStringContainsString('Mine', $csv);
        $this->assertStringNotContainsString('Other', $csv);
        $this->assertStringContainsString((string) $mineReport->id, $csv);
    }

    public function test_export_is_audited(): void
    {
        $topic = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        Report::create(['topic_id' => $topic->id, 'state' => ReportState::Open]);

        $this->actingAsGlobalAdmin()->get('/dashboard/export?filters=1&hide_closed=0&hide_spam=0')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.exported', 'actor' => 'globaladmin']);
        $log = AuditLog::where('action', 'reports.exported')->firstOrFail();
        $this->assertSame(1, $log->metadata['count'] ?? null);
    }
}
