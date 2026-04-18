<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedReport(): Report
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'email' => 'it-sec@tum.de',
        ]);
        $report = Report::create([
            'topic_id' => $topic->id,
            'creator' => 'anon@example.com',
        ]);
        Message::create([
            'report_id' => $report->id,
            'content' => 'initial message',
            'is_admin' => false,
        ]);

        return $report->fresh(['messages', 'topic']) ?? $report;
    }

    public function test_report_requires_token(): void
    {
        $this->get('/report')->assertNotFound();
    }

    public function test_report_with_reporter_token_renders(): void
    {
        $r = $this->seedReport();
        $this->get('/report?reporterToken='.$r->reporter_token)
            ->assertOk()
            ->assertSee('initial message');
    }

    public function test_report_with_unknown_token_404s(): void
    {
        $this->get('/report?reporterToken=unknown')->assertNotFound();
    }

    public function test_reply_appends_message(): void
    {
        $r = $this->seedReport();

        $this->post('/report?reporterToken='.$r->reporter_token, [
            'reply' => 'user follow-up',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'report_id' => $r->id,
            'content' => 'user follow-up',
            'is_admin' => false,
        ]);
    }

    public function test_admin_reply_is_flagged_as_admin(): void
    {
        $r = $this->seedReport();

        $this->post('/report?administratorToken='.$r->administrator_token, [
            'reply' => 'admin follow-up',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'report_id' => $r->id,
            'content' => 'admin follow-up',
            'is_admin' => true,
        ]);
    }

    public function test_reply_rejects_empty(): void
    {
        $r = $this->seedReport();

        $this->post('/report?reporterToken='.$r->reporter_token, [
            'reply' => '',
        ])->assertStatus(400);
    }
}
