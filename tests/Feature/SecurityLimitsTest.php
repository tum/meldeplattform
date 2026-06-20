<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function webhookTopic(string $target): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['webhook' => ['target' => $target]],
        ]);
    }

    private function messageFor(Topic $topic): Message
    {
        $report = Report::create(['topic_id' => $topic->id]);

        return Message::create(['report_id' => $report->id, 'content' => 'hi', 'is_admin' => false]);
    }

    public function test_webhook_is_not_sent_over_plain_http(): void
    {
        Http::fake();
        $topic = $this->webhookTopic('http://insecure.example/hook');

        app(MessengerDispatcher::class)->sendNow($topic, 'T', $this->messageFor($topic), 'https://app/report');

        Http::assertNothingSent();
    }

    public function test_webhook_is_sent_over_https(): void
    {
        Http::fake();
        $topic = $this->webhookTopic('https://secure.example/hook');

        app(MessengerDispatcher::class)->sendNow($topic, 'T', $this->messageFor($topic), 'https://app/report');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://secure.example/hook');
    }

    public function test_reply_rejects_overlong_body(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $report = Report::create(['topic_id' => $topic->id]);

        $this->postJson('/report', [
            'reporterToken' => $report->reporter_token,
            'reply' => str_repeat('a', 50001),
        ])->assertStatus(422)->assertJsonValidationErrors(['reply']);
    }

    public function test_field_value_rejects_overlong_text(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $field = Field::create([
            'topic_id' => $topic->id, 'name_de' => 'F', 'name_en' => 'F',
            'type' => 'textarea', 'required' => true, 'position' => 0,
        ]);

        $this->postJson('/submit', [
            'topic' => $topic->id,
            (string) $field->id => str_repeat('a', 50001),
        ])->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }
}
