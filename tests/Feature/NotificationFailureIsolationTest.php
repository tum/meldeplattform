<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A failing notification channel must never fail the request that triggered it.
 *
 * This deployment mandates QUEUE_CONNECTION=sync (LRZ shared hosting runs no
 * workers), so DispatchTopicNotifications executes inline and SyncQueue
 * re-throws sendNow()'s failure into the caller. That used to 500 the reporter's
 * submit *after* the report was committed but *before* the receipt code was
 * issued — so the allegation was stored while the anonymous reporter lost every
 * way back to it, and the handlers were never notified either.
 *
 * These tests all run on the real sync driver (no Queue::fake) — faking the
 * queue is exactly what hid this.
 */
class NotificationFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        // Every channel this topic uses is down.
        Http::fake(['hook.example/*' => Http::response('gateway timeout', 504)]);
    }

    private function topicWithFailingWebhook(): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['webhook' => ['target' => 'https://hook.example/down']],
        ]);
    }

    private function textField(Topic $topic): Field
    {
        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'textarea', 'required' => true, 'position' => 0,
        ]);
    }

    public function test_reporter_keeps_access_to_their_report_when_the_webhook_is_down(): void
    {
        $topic = $this->topicWithFailingWebhook();
        $field = $this->textField($topic);

        $response = $this->post(route('form.submit'), [
            'topic' => $topic->id,
            (string) $field->id => 'The allegation text.',
        ]);

        $report = Report::first();
        $this->assertNotNull($report, 'the report was not stored');

        // The three things the reporter needs, all lost when dispatch re-threw.
        $response->assertRedirect(route('report.show', ['reporterToken' => $report->reporter_token]));
        $this->assertNotNull(session('receipt_code'), 'no receipt code was issued');
        $this->assertNotNull($report->receipt_hash, 'no receipt hash was stored — the code can never be redeemed');
    }

    public function test_the_report_is_actually_reachable_afterwards(): void
    {
        $topic = $this->topicWithFailingWebhook();
        $field = $this->textField($topic);

        $this->post(route('form.submit'), [
            'topic' => $topic->id,
            (string) $field->id => 'The allegation text.',
        ]);
        $report = Report::firstOrFail();

        // Both ways back in must work: the token URL and the receipt code.
        $this->get(route('report.show', ['reporterToken' => $report->reporter_token]))->assertOk();
        $this->post(route('report.track.submit'), ['code' => session('receipt_code')])->assertRedirect();
    }

    public function test_a_failed_dispatch_is_logged_critically_rather_than_swallowed(): void
    {
        $logger = Log::spy();
        $topic = $this->topicWithFailingWebhook();
        $field = $this->textField($topic);

        $this->post(route('form.submit'), [
            'topic' => $topic->id,
            (string) $field->id => 'The allegation text.',
        ])->assertRedirect();

        // Silence would be worse than the 500: a team was not notified.
        $logger->shouldHaveReceived('critical');
    }

    public function test_admin_reply_survives_a_failing_channel(): void
    {
        $topic = $this->topicWithFailingWebhook();
        $report = Report::create(['topic_id' => $topic->id]);
        Message::create(['report_id' => $report->id, 'content' => 'hello', 'is_admin' => false]);

        $this->actingAsGlobalAdmin()
            ->post("/reports/{$topic->id}/{$report->id}/reply", ['reply' => 'We received your report.'])
            ->assertRedirect();

        $this->assertSame(2, $report->messages()->count(), 'the admin reply was lost');
    }

    public function test_reporter_reply_survives_a_failing_channel(): void
    {
        $topic = $this->topicWithFailingWebhook();
        $report = Report::create(['topic_id' => $topic->id]);

        $this->post(route('report.reply'), [
            'reporterToken' => $report->reporter_token,
            'reply' => 'Adding more detail.',
        ])->assertRedirect();

        $this->assertSame(1, $report->messages()->count(), 'the reporter reply was lost');
    }

    public function test_a_working_channel_still_receives_the_notification(): void
    {
        // Control: isolation must not have turned the fan-out into a no-op.
        Http::fake(['hook.example/*' => Http::response('', 200)]);

        $topic = $this->topicWithFailingWebhook();
        $field = $this->textField($topic);

        $this->post(route('form.submit'), [
            'topic' => $topic->id,
            (string) $field->id => 'The allegation text.',
        ])->assertRedirect();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hook.example/down');
    }
}
