<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `reports:prune` discharges a statutory duty (HinSchG §11(5): delete the
 * documentation three years after the procedure is concluded). Under-deleting
 * is a compliance failure, and one that stayed silent — the command reported
 * more deletions than it performed.
 */
class RetentionDutiesTest extends TestCase
{
    use RefreshDatabase;

    /** artisan() is typed PendingCommand|int; narrow it once so callers can chain. */
    private function prune(string $args = ''): PendingCommand
    {
        $command = $this->artisan(trim('reports:prune '.$args));
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function topicWithRetention(int $days = 30): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '',
            'retention_days' => $days,
        ]);
    }

    /** @return list<int> ids of the created reports */
    private function makeConcludedReports(Topic $topic, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Report::create([
                'topic_id' => $topic->id,
                'state' => 'done',
                'closed_at' => Carbon::now()->subDays(400),
            ])->id;
        }

        return $ids;
    }

    public function test_prune_deletes_every_due_report_across_chunk_boundaries(): void
    {
        // >1000 forces a second page. each()/chunk() page with OFFSET, so
        // deleting page 1 shifted the rest left and page 2 skipped them:
        // 2500 due -> 1000 survived, while the command claimed 1500 pruned.
        $topic = $this->topicWithRetention();
        $this->makeConcludedReports($topic, 2500);

        $this->prune()->assertSuccessful();

        $this->assertSame(0, Report::count(), 'reports past their statutory deletion date survived the run');
    }

    public function test_prune_reports_the_number_it_actually_deleted(): void
    {
        $topic = $this->topicWithRetention();
        $this->makeConcludedReports($topic, 1200);

        $this->prune()
            ->expectsOutputToContain('Pruned 1200 report(s).')
            ->assertSuccessful();
    }

    public function test_dry_run_matches_what_a_real_run_deletes(): void
    {
        // The dry-run doesn't mutate, so it always paged correctly and reported
        // 2500 while a real run deleted 1500 — the preview contradicted reality.
        $topic = $this->topicWithRetention();
        $this->makeConcludedReports($topic, 1200);

        $this->prune('--dry-run')
            ->expectsOutputToContain('Would prune 1200 report(s).')
            ->assertSuccessful();

        $this->assertSame(1200, Report::count(), 'dry-run deleted something');

        $this->prune()->assertSuccessful();
        $this->assertSame(0, Report::count());
    }

    public function test_open_reports_are_never_pruned_however_old(): void
    {
        $topic = $this->topicWithRetention();
        $open = Report::create(['topic_id' => $topic->id, 'state' => 'open']);
        $open->forceFill(['created_at' => Carbon::now()->subDays(4000)])->saveQuietly();
        $this->makeConcludedReports($topic, 3);

        $this->prune()->assertSuccessful();

        $this->assertNotNull(Report::find($open->id), 'an unconcluded report was deleted');
        $this->assertSame(1, Report::count());
    }

    public function test_recently_concluded_reports_are_kept(): void
    {
        $topic = $this->topicWithRetention(30);
        $recent = Report::create([
            'topic_id' => $topic->id,
            'state' => 'done',
            'closed_at' => Carbon::now()->subDays(5),
        ]);

        $this->prune()->assertSuccessful();

        $this->assertNotNull(Report::find($recent->id));
    }

    public function test_an_undeletable_blob_does_not_abort_the_run(): void
    {
        // try/finally with no catch re-threw after the finally, so a single
        // unreadable blob killed the whole night's run — including every later
        // topic, all of which are also past their deletion date.
        $topic = $this->topicWithRetention();
        $report = Report::create([
            'topic_id' => $topic->id,
            'state' => 'done',
            'closed_at' => Carbon::now()->subDays(400),
        ]);
        $message = Message::create(['report_id' => $report->id, 'content' => 'body', 'is_admin' => false]);
        $file = File::create([
            'uuid' => (string) Str::uuid(),
            'path' => 'does-not-exist.pdf',
            'name' => 'attachment-x.pdf',
            // A disk that isn't configured makes Storage::disk() throw.
            'disk' => 'no-such-disk',
        ]);
        $message->files()->sync([$file->id]);

        $laterTopic = $this->topicWithRetention();
        $laterIds = $this->makeConcludedReports($laterTopic, 2);

        $this->prune()->assertSuccessful();

        $this->assertNull(Report::find($report->id), 'the report with the bad blob was not deleted');
        $this->assertNull(File::find($file->id), 'the File row was left behind');
        foreach ($laterIds as $id) {
            $this->assertNull(Report::find($id), 'a later topic was skipped because one blob failed');
        }
    }

    public function test_prune_names_otrs_tickets_it_cannot_delete(): void
    {
        // OTRS holds the full report body and this command cannot delete it;
        // after the row is gone the ticket id exists nowhere else, so the run
        // must name it or the duty fails silently and untraceably.
        $topic = $this->topicWithRetention();
        $report = Report::create([
            'topic_id' => $topic->id,
            'state' => 'done',
            'closed_at' => Carbon::now()->subDays(400),
        ]);
        $report->forceFill([
            'otrs_ticket_id' => '4711',
            'otrs_ticket_number' => '2026010112345678',
        ])->saveQuietly();

        $this->prune()
            ->expectsOutputToContain('2026010112345678')
            ->assertSuccessful();
    }

    public function test_prune_stays_quiet_when_no_otrs_tickets_are_involved(): void
    {
        $topic = $this->topicWithRetention();
        $this->makeConcludedReports($topic, 2);

        $this->prune()
            ->doesntExpectOutputToContain('OTRS')
            ->assertSuccessful();
    }
}
