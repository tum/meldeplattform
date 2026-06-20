<?php

namespace Tests\Feature;

use App\Mail\DeadlineReminder;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeadlineReminderTest extends TestCase
{
    use RefreshDatabase;

    private function topicWithMailbox(string $email = 'handler@example.com'): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '',
            'email' => $email,
        ]);
    }

    private function reportCreatedDaysAgo(Topic $topic, int $daysAgo): Report
    {
        $this->travelTo(now()->subDays($daysAgo));
        $report = Report::create(['topic_id' => $topic->id]);
        $this->travelBack();

        return $report;
    }

    public function test_emails_handlers_about_an_overdue_acknowledgement(): void
    {
        Mail::fake();
        $topic = $this->topicWithMailbox();
        // 8 days old, still unacknowledged → past the 7-day ack window.
        $this->reportCreatedDaysAgo($topic, 8);

        $this->assertSame(0, Artisan::call('reports:remind'));

        // DeadlineReminder is sent synchronously (not queued), so assertSent.
        Mail::assertSent(DeadlineReminder::class, function (DeadlineReminder $mail): bool {
            return $mail->hasTo('handler@example.com') && count($mail->items) >= 1;
        });
    }

    public function test_no_email_when_nothing_is_near_a_deadline(): void
    {
        Mail::fake();
        $topic = $this->topicWithMailbox();
        // Fresh report: ack due in 7 days, lead time is 2 → not yet flagged.
        $this->reportCreatedDaysAgo($topic, 0);

        $this->assertSame(0, Artisan::call('reports:remind'));

        Mail::assertNothingSent();
    }

    public function test_no_email_when_topic_has_no_mailbox(): void
    {
        Mail::fake();
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
        $this->reportCreatedDaysAgo($topic, 100);

        $this->assertSame(0, Artisan::call('reports:remind'));

        Mail::assertNothingSent();
    }

    public function test_dry_run_sends_no_mail(): void
    {
        Mail::fake();
        $topic = $this->topicWithMailbox();
        $this->reportCreatedDaysAgo($topic, 8);

        $this->assertSame(0, Artisan::call('reports:remind', ['--dry-run' => true]));

        Mail::assertNothingSent();
    }
}
