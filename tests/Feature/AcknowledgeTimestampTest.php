<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard: acknowledging a report is admin housekeeping, not new
 * thread activity, so it must NOT bump `reports.updated_at`. That column drives
 * the home-page unread badge (reports.updated_at > topic_views.last_seen_at);
 * bumping it re-surfaces a report the admin just triaged as "unread" for every
 * admin of the topic. The bug was `acknowledge()` running an Eloquent-builder
 * update (which injects `updated_at`) instead of a base-builder update.
 */
class AcknowledgeTimestampTest extends TestCase
{
    use RefreshDatabase;

    private function topic(): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '',
        ]);
    }

    private function reportCreatedDaysAgo(Topic $topic, int $daysAgo): Report
    {
        $this->travelTo(now()->subDays($daysAgo));
        $report = Report::create(['topic_id' => $topic->id]);
        $this->travelBack();

        return $report;
    }

    /** Reload from the DB and assert the row still exists (narrows away null). */
    private function freshReport(Report $report): Report
    {
        $fresh = $report->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    public function test_acknowledge_sets_acknowledged_at_without_bumping_updated_at(): void
    {
        $report = $this->reportCreatedDaysAgo($this->topic(), 3);
        $before = $this->freshReport($report)->updated_at?->toDateTimeString();
        $this->assertNotNull($before);

        $report->acknowledge();

        $fresh = $this->freshReport($report);
        $this->assertNotNull($fresh->acknowledged_at, 'acknowledged_at must be stamped');
        $this->assertSame(
            $before,
            $fresh->updated_at?->toDateTimeString(),
            'acknowledge() must not bump updated_at (it drives the unread badge)',
        );
    }

    public function test_acknowledge_route_does_not_bump_updated_at(): void
    {
        $topic = $this->topic();
        $report = $this->reportCreatedDaysAgo($topic, 3);
        $before = $this->freshReport($report)->updated_at?->toDateTimeString();
        $this->assertNotNull($before);

        $this->actingAsGlobalAdmin()
            ->postJson(route('report.acknowledge', ['topic' => $topic->id, 'report' => $report->id]))
            ->assertOk();

        $fresh = $this->freshReport($report);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame($before, $fresh->updated_at?->toDateTimeString());
    }
}
