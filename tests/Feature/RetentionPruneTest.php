<?php

namespace Tests\Feature;

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
     * Create a report (with one message + one uploaded file) whose timestamps
     * are $daysAgo days in the past.
     */
    private function makeReportAgedDays(Topic $topic, int $daysAgo): Report
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
