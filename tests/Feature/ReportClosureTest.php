<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `closed_at` is the retention anchor (HinSchG §11(5): delete 3 years after the
 * procedure is concluded), so it must be stamped on conclusion and cleared on
 * reopen across both the single-report and bulk status paths.
 */
class ReportClosureTest extends TestCase
{
    use RefreshDatabase;

    private function topic(): Topic
    {
        return Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
    }

    public function test_closing_a_report_stamps_closed_at(): void
    {
        $report = Report::create(['topic_id' => $this->topic()->id]);
        $this->assertNull($report->closed_at);

        $report->state = ReportState::Done;
        $report->save();

        $this->assertNotNull($report->refresh()->closed_at);
    }

    public function test_spam_also_stamps_closed_at(): void
    {
        $report = Report::create(['topic_id' => $this->topic()->id]);

        $report->state = ReportState::Spam;
        $report->save();

        $this->assertNotNull($report->refresh()->closed_at);
    }

    public function test_reopening_clears_closed_at(): void
    {
        $report = Report::create(['topic_id' => $this->topic()->id, 'state' => ReportState::Done]);
        $this->assertNotNull($report->refresh()->closed_at);

        $report->state = ReportState::Open;
        $report->save();

        $this->assertNull($report->refresh()->closed_at);
    }

    public function test_resaving_without_a_state_change_keeps_the_conclusion_date(): void
    {
        $report = Report::create(['topic_id' => $this->topic()->id]);

        $this->travelTo(now()->subDays(10));
        $report->state = ReportState::Done;
        $report->save();
        $this->travelBack();

        $original = $report->refresh()->closed_at;
        $this->assertNotNull($original);

        // Saving again without changing state must not reset the stamp.
        $report->save();
        $reclosed = $report->refresh()->closed_at;
        $this->assertNotNull($reclosed);
        $this->assertTrue($reclosed->equalTo($original));
    }

    public function test_bulk_close_stamps_and_bulk_reopen_clears_closed_at(): void
    {
        $topic = $this->topic();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/reports/status", ['ids' => [$report->id], 's' => 'close'])
            ->assertOk();
        $this->assertNotNull($report->refresh()->closed_at);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/reports/status", ['ids' => [$report->id], 's' => 'open'])
            ->assertOk();
        $this->assertNull($report->refresh()->closed_at);
    }
}
