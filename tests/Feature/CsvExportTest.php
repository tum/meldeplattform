<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_auth(): void
    {
        $this->get('/dashboard/export')->assertRedirect('/dev/login');
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
