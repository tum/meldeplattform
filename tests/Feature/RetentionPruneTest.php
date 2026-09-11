<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\AuditLog;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    private function makeTopic(?int $retentionDays): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'retention_days' => $retentionDays,
        ]);
    }

    /**
     * Create a report (with one message + one uploaded file) concluded
     * $daysAgo days in the past. Retention is anchored on `closed_at`, so the
     * report is closed at that point unless $conclude is false (in which case
     * it stays Open and must never be pruned).
     */
    private function makeReportAgedDays(Topic $topic, int $daysAgo, bool $conclude = true, ReportState $state = ReportState::Done): Report
    {
        $this->travelTo(now()->subDays($daysAgo));

        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'body', 'is_admin' => false,
        ]);
        Storage::disk('uploads')->put('blob.txt', 'secret');
        $file = File::create([
            'path' => 'blob.txt', 'disk' => 'uploads', 'name' => 'evidence.txt',
        ]);
        $message->files()->attach($file->id);

        if ($conclude) {
            // Closing stamps `closed_at` at the (travelled) current time, which
            // is the retention anchor the prune command measures against.
            $report->state = $state;
            $report->save();
        }

        $this->travelBack();

        return $report;
    }

    public function test_prunes_reports_past_retention_window_and_their_files(): void
    {
        Storage::fake('uploads');
        $topic = $this->makeTopic(30);
        $report = $this->makeReportAgedDays($topic, 40);

        $this->assertTrue(Storage::disk('uploads')->exists('blob.txt'));

        $this->assertSame(0, Artisan::call('reports:prune'));

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertFalse(Storage::disk('uploads')->exists('blob.txt'));
    }

    public function test_spam_goes_after_its_own_shorter_window(): void
    {
        Storage::fake('uploads');
        config(['meldeplattform.spam_retention_days' => 90]);
        $topic = $this->makeTopic(1095);
        $spam = $this->makeReportAgedDays($topic, 100, state: ReportState::Spam);
        $genuine = $this->makeReportAgedDays($topic, 100);
        $freshSpam = $this->makeReportAgedDays($topic, 30, state: ReportState::Spam);

        Artisan::call('reports:prune');

        $this->assertDatabaseMissing('reports', ['id' => $spam->id]);
        $this->assertDatabaseHas('reports', ['id' => $genuine->id]);
        $this->assertDatabaseHas('reports', ['id' => $freshSpam->id]);
        $this->assertStringContainsString('Pruned 1 report(s), 1 of them spam.', Artisan::output());
    }

    public function test_spam_window_never_exceeds_the_topic_window(): void
    {
        Storage::fake('uploads');
        config(['meldeplattform.spam_retention_days' => 90]);
        $topic = $this->makeTopic(30);
        $spam = $this->makeReportAgedDays($topic, 40, state: ReportState::Spam);

        Artisan::call('reports:prune');

        $this->assertDatabaseMissing('reports', ['id' => $spam->id]);
    }

    public function test_spam_follows_the_topic_window_when_its_own_is_disabled(): void
    {
        Storage::fake('uploads');
        config(['meldeplattform.spam_retention_days' => null]);
        $topic = $this->makeTopic(1095);
        $spam = $this->makeReportAgedDays($topic, 100, state: ReportState::Spam);

        Artisan::call('reports:prune');

        $this->assertDatabaseHas('reports', ['id' => $spam->id]);
    }

    public function test_run_that_deleted_something_is_audit_logged(): void
    {
        Storage::fake('uploads');
        $topic = $this->makeTopic(30);
        $this->makeReportAgedDays($topic, 40);

        Artisan::call('reports:prune');

        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.pruned', 'actor' => 'system']);
        $entry = AuditLog::where('action', 'reports.pruned')->firstOrFail();
        $this->assertSame(1, $entry->metadata['count'] ?? null);
        $this->assertSame([(string) $topic->id => 1], $entry->metadata['per_topic'] ?? null);

        // A run with nothing to do leaves no trace.
        Artisan::call('reports:prune');
        $this->assertSame(1, AuditLog::where('action', 'reports.pruned')->count());
    }

    public function test_keeps_reports_within_retention_window(): void
    {
        Storage::fake('uploads');
        $topic = $this->makeTopic(30);
        $report = $this->makeReportAgedDays($topic, 5);

        $this->assertSame(0, Artisan::call('reports:prune'));

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertTrue(Storage::disk('uploads')->exists('blob.txt'));
    }

    public function test_keeps_everything_when_no_retention_configured(): void
    {
        Storage::fake('uploads');
        config(['meldeplattform.default_retention_days' => null]);
        $topic = $this->makeTopic(null);
        $report = $this->makeReportAgedDays($topic, 9999);

        $this->assertSame(0, Artisan::call('reports:prune'));

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }

    public function test_global_default_applies_when_topic_has_no_override(): void
    {
        Storage::fake('uploads');
        config(['meldeplattform.default_retention_days' => 30]);
        $topic = $this->makeTopic(null);
        $report = $this->makeReportAgedDays($topic, 40);

        $this->assertSame(0, Artisan::call('reports:prune'));

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
    }

    public function test_keeps_open_reports_even_when_aged_past_window(): void
    {
        // A still-open procedure is never auto-deleted, regardless of age:
        // the statutory clock (HinSchG §11(5)) only starts at conclusion.
        Storage::fake('uploads');
        $topic = $this->makeTopic(30);
        $report = $this->makeReportAgedDays($topic, 9999, conclude: false);

        $this->assertSame(0, Artisan::call('reports:prune'));

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertTrue(Storage::disk('uploads')->exists('blob.txt'));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        Storage::fake('uploads');
        $topic = $this->makeTopic(30);
        $report = $this->makeReportAgedDays($topic, 40);

        $this->assertSame(0, Artisan::call('reports:prune', ['--dry-run' => true]));

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertTrue(Storage::disk('uploads')->exists('blob.txt'));
    }
}
