<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SlaDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function makeTopic(): Topic
    {
        return Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
    }

    public function test_fresh_report_is_not_acknowledged_and_due_dates_match_config(): void
    {
        config(['meldeplattform.acknowledgement_deadline_days' => 7]);
        config(['meldeplattform.feedback_deadline_days' => 90]);

        $report = Report::create(['topic_id' => $this->makeTopic()->id]);
        $created = $report->created_at;
        $this->assertNotNull($created);

        $this->assertFalse($report->isAcknowledged());
        $this->assertNull($report->acknowledged_at);
        $this->assertTrue($report->acknowledgementDueAt()?->equalTo($created->copy()->addDays(7)));
        $this->assertTrue($report->feedbackDueAt()?->equalTo($created->copy()->addDays(90)));
    }

    public function test_overdue_now_scope_counts_only_truly_overdue_reports(): void
    {
        $topic = $this->makeTopic();
        $overdue = Report::create(['topic_id' => $topic->id]);   // will age past the window
        $fresh = Report::create(['topic_id' => $topic->id]);     // stays recent

        $this->travel(8)->days();
        // Keep `$fresh` recent by touching its created_at to now.
        $fresh->forceFill(['created_at' => Carbon::now()])->saveQuietly();

        $ids = Report::query()->overdueNow()->pluck('id')->all();
        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($fresh->id, $ids);

        // Acknowledging + closing removes it from the overdue set.
        $overdue->acknowledge();
        $overdue->update(['state' => ReportState::Done]);
        $this->assertSame(0, Report::query()->overdueNow()->count());
    }

    public function test_acknowledgement_overdue_when_older_than_window_and_clears_on_acknowledge(): void
    {
        $report = Report::create(['topic_id' => $this->makeTopic()->id]);

        $this->travel(8)->days();
        $this->assertTrue($report->isAcknowledgementOverdue());

        $report->acknowledge();
        $this->assertTrue($report->isAcknowledged());
        $this->assertFalse($report->isAcknowledgementOverdue());
    }

    public function test_acknowledge_is_idempotent(): void
    {
        $report = Report::create(['topic_id' => $this->makeTopic()->id]);
        $report->acknowledge();
        $first = $report->acknowledged_at;
        $this->assertNotNull($first);

        $this->travel(1)->days();
        $report->acknowledge();
        $this->assertTrue($report->acknowledged_at?->equalTo($first));
    }

    public function test_feedback_overdue_when_past_window_and_clears_when_closed(): void
    {
        $report = Report::create(['topic_id' => $this->makeTopic()->id]);

        $this->travel(91)->days();
        $this->assertTrue($report->isFeedbackOverdue());

        $report->state = ReportState::Done;
        $this->assertFalse($report->isFeedbackOverdue());
    }

    public function test_closed_report_is_never_acknowledgement_overdue(): void
    {
        $report = Report::create(['topic_id' => $this->makeTopic()->id, 'state' => ReportState::Done]);

        $this->travel(30)->days();
        $this->assertFalse($report->isAcknowledgementOverdue());
    }

    public function test_admin_reply_auto_acknowledges(): void
    {
        $topic = $this->makeTopic();
        $report = Report::create(['topic_id' => $topic->id]);
        Message::create(['report_id' => $report->id, 'content' => 'hello', 'is_admin' => false]);
        $this->assertFalse($report->isAcknowledged());

        $this->post('/report', [
            'administratorToken' => $report->administrator_token,
            'reply' => 'We received your report.',
        ])->assertRedirect();

        $this->assertTrue(Report::findOrFail($report->id)->isAcknowledged());
    }

    public function test_reporter_reply_does_not_acknowledge(): void
    {
        $topic = $this->makeTopic();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->post('/report', [
            'reporterToken' => $report->reporter_token,
            'reply' => 'Adding more detail.',
        ])->assertRedirect();

        $this->assertFalse(Report::findOrFail($report->id)->isAcknowledged());
    }

    public function test_set_status_progress_sets_in_progress_and_still_allows_reply(): void
    {
        $topic = $this->makeTopic();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/report/{$report->id}/status", ['s' => 'progress'])
            ->assertOk();

        $fresh = Report::findOrFail($report->id);
        $this->assertSame(ReportState::InProgress, $fresh->state);
        $this->assertTrue($fresh->state->allowsReply());
        // Moving to in-progress also acknowledges.
        $this->assertTrue($fresh->isAcknowledged());
    }

    public function test_acknowledge_route_requires_admin(): void
    {
        $topic = $this->makeTopic();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsUser('nobody')
            ->postJson("/api/topic/{$topic->id}/report/{$report->id}/acknowledge")
            ->assertStatus(403);

        $this->assertFalse(Report::findOrFail($report->id)->isAcknowledged());
    }

    public function test_acknowledge_route_sets_acknowledged_at(): void
    {
        Carbon::setTestNow('2026-06-20 12:00:00');
        $topic = $this->makeTopic();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$topic->id}/report/{$report->id}/acknowledge")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $fresh = Report::findOrFail($report->id);
        $this->assertTrue($fresh->isAcknowledged());
        $this->assertTrue($fresh->acknowledged_at?->equalTo(Carbon::parse('2026-06-20 12:00:00')));
        Carbon::setTestNow();
    }
}
