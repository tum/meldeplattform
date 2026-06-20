<?php

namespace Tests\Feature;

use App\Jobs\DispatchTopicNotifications;
use App\Mail\ReportNotification;
use App\Models\Field;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use App\Services\Messengers\EmailMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_report_queues_the_notification_job(): void
    {
        Queue::fake();

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'email' => 'it-sec@tum.de',
        ]);
        $field = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'textarea', 'required' => true, 'position' => 0,
        ]);

        $this->post('/submit', [
            'topic' => $topic->id,
            (string) $field->id => 'a report body',
        ])->assertRedirect();

        Queue::assertPushed(DispatchTopicNotifications::class);
    }

    public function test_send_now_fans_out_to_configured_messengers(): void
    {
        Mail::fake();

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'email' => 'it-sec@tum.de',
        ]);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'hi', 'is_admin' => false,
        ]);

        app(MessengerDispatcher::class)->sendNow($topic, 'Title', $message, 'https://app/report');

        // The email-configured topic yields exactly one EmailMessenger, which
        // sends the notification mailable.
        $this->assertCount(1, app(MessengerDispatcher::class)->forTopic($topic));
        $this->assertInstanceOf(
            EmailMessenger::class,
            app(MessengerDispatcher::class)->forTopic($topic)[0],
        );
        // ReportNotification implements ShouldQueue, so the fake records it as
        // queued rather than sent.
        Mail::assertQueued(ReportNotification::class);
    }

    public function test_webhook_payload_is_hmac_signed_when_secret_configured(): void
    {
        config(['meldeplattform.webhook_secret' => 'topsecret']);
        Http::fake();

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['webhook' => ['target' => 'https://hook.example/notify']],
        ]);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'hi', 'is_admin' => false,
        ]);

        app(MessengerDispatcher::class)->sendNow($topic, 'Title', $message, 'https://app/report');

        Http::assertSent(function (ClientRequest $request): bool {
            $sent = $request->header('X-SafeSignal-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'topsecret');

            return hash_equals($expected, $sent);
        });
    }

    public function test_failed_channel_bubbles_up_so_the_job_can_retry(): void
    {
        Http::fake(['hook.example/*' => Http::response('boom', 500)]);

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['webhook' => ['target' => 'https://hook.example/notify']],
        ]);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'hi', 'is_admin' => false,
        ]);

        $this->expectException(RuntimeException::class);

        app(MessengerDispatcher::class)->sendNow($topic, 'Title', $message, 'https://app/report');
    }

    public function test_dispatch_job_is_retryable(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'hi', 'is_admin' => false,
        ]);

        $job = new DispatchTopicNotifications($topic, 'Title', $message, 'https://app/report');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff());
    }
}
