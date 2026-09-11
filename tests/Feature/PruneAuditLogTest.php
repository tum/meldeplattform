<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['meldeplattform.audit_retention_days' => 1095]);
    }

    private function entryAgedDays(int $days, string $action, ?Report $subject = null): AuditLog
    {
        $this->travelTo(now()->subDays($days));
        $entry = AuditLog::record($action, $subject);
        $this->travelBack();

        return $entry;
    }

    public function test_prunes_entries_past_window_and_records_the_run(): void
    {
        $old = $this->entryAgedDays(1200, 'topic.updated');
        $recent = $this->entryAgedDays(10, 'topic.updated');

        $this->assertSame(0, Artisan::call('audit:prune'));

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        $summary = AuditLog::where('action', 'audit.pruned')->firstOrFail();
        $this->assertSame(1, $summary->metadata['count'] ?? null);
    }

    public function test_keeps_old_entries_about_a_report_that_still_exists(): void
    {
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
        $living = Report::create(['topic_id' => $topic->id]);
        $gone = Report::create(['topic_id' => $topic->id]);

        $keep = $this->entryAgedDays(1200, 'report.accessed', $living);
        $drop = $this->entryAgedDays(1200, 'report.accessed', $gone);
        $gone->delete();

        Artisan::call('audit:prune');

        // The access trail outlives nothing but the case file itself.
        $this->assertDatabaseHas('audit_logs', ['id' => $keep->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $drop->id]);
    }

    public function test_dry_run_deletes_nothing_and_writes_no_summary(): void
    {
        $old = $this->entryAgedDays(1200, 'topic.updated');

        Artisan::call('audit:prune', ['--dry-run' => true]);

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'audit.pruned']);
        $this->assertStringContainsString('Would prune 1 audit entry', Artisan::output());
    }

    public function test_no_op_when_disabled(): void
    {
        config(['meldeplattform.audit_retention_days' => null]);
        $old = $this->entryAgedDays(1200, 'topic.updated');

        Artisan::call('audit:prune');

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
    }
}
