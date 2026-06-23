<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Mail\ReportNotification;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtrsReplyPollTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, bool|int|string> */
    private const OTRS_CONFIG = [
        'base_url' => 'https://otrs.test/otrs/nph-genericinterface.pl/Webservice/GTC',
        'user_login' => 'svc',
        'password' => 'pw',
        'default_queue' => 'Raw',
        'default_priority' => '3 normal',
        'default_state' => 'new',
        'customer_user' => 'safesignal',
        'ticket_type' => '',
        'timeout' => 10,
        'inbound_enabled' => true,
    ];

    /** @param array<string, bool|int|string> $overrides */
    private function configureOtrs(array $overrides = []): void
    {
        config(['meldeplattform.otrs' => array_merge(self::OTRS_CONFIG, $overrides)]);
    }

    private function ticketedReport(?string $creator = null, ?string $lastArticleId = null): Report
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['otrs' => ['queue' => 'Whistleblowing']],
        ]);
        $report = Report::create(['topic_id' => $topic->id, 'creator' => $creator]);
        $report->forceFill([
            'otrs_ticket_id' => '4242',
            'otrs_last_article_id' => $lastArticleId,
        ])->save();

        return $report;
    }

    /**
     * @param list<array<string, int|string>> $articles
     */
    private function fakeTicketGet(array $articles): void
    {
        Http::fake([
            '*' => Http::response(['Ticket' => [['TicketID' => '4242', 'Article' => $articles]]], 200),
        ]);
    }

    public function test_imports_customer_visible_agent_answer_as_admin_message(): void
    {
        $this->configureOtrs();
        Mail::fake();
        $this->fakeTicketGet([
            ['ArticleID' => '10', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1,
                'Subject' => 'Re', 'Body' => 'Hallo Melder', 'ContentType' => 'text/plain; charset=utf8'],
        ]);
        $report = $this->ticketedReport();

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $report->refresh();
        $this->assertCount(1, $report->messages);
        $message = $report->messages->first();
        $this->assertNotNull($message);
        $this->assertSame('Hallo Melder', $message->content);
        $this->assertTrue($message->is_admin);
        $this->assertSame('otrs', $message->source);
        // Cursor advanced and the agent answer acknowledged the report.
        $this->assertSame('10', $report->otrs_last_article_id);
        $this->assertTrue($report->isAcknowledged());
    }

    public function test_skips_internal_pushes_customer_and_system_articles(): void
    {
        $this->configureOtrs();
        $this->fakeTicketGet([
            // Our own outbound push: agent-authored but never customer-visible.
            ['ArticleID' => '7', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 0, 'Body' => 'internal mirror'],
            // The reporter's own message echoed in OTRS.
            ['ArticleID' => '8', 'SenderType' => 'customer', 'IsVisibleForCustomer' => 1, 'Body' => 'reporter text'],
            // An automated system note.
            ['ArticleID' => '9', 'SenderType' => 'system', 'IsVisibleForCustomer' => 1, 'Body' => 'state change'],
        ]);
        $report = $this->ticketedReport();

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $report->refresh();
        $this->assertCount(0, $report->messages);
        // Nothing imported, so the cursor and acknowledgement are untouched.
        $this->assertNull($report->otrs_last_article_id);
        $this->assertFalse($report->isAcknowledged());
    }

    public function test_does_not_reimport_articles_at_or_below_the_cursor(): void
    {
        $this->configureOtrs();
        $this->fakeTicketGet([
            ['ArticleID' => '10', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'already seen'],
            ['ArticleID' => '11', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'fresh answer'],
        ]);
        $report = $this->ticketedReport(lastArticleId: '10');

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $report->refresh();
        $this->assertCount(1, $report->messages);
        $this->assertSame('fresh answer', $report->messages->first()?->content);
        $this->assertSame('11', $report->otrs_last_article_id);
    }

    public function test_imports_multiple_answers_oldest_first_and_advances_to_max(): void
    {
        $this->configureOtrs();
        // Returned out of order; must be imported oldest-first.
        $this->fakeTicketGet([
            ['ArticleID' => '13', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'second'],
            ['ArticleID' => '12', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'first'],
        ]);
        $report = $this->ticketedReport();

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $report->refresh();
        $this->assertSame(['first', 'second'], $report->messages->pluck('content')->all());
        $this->assertSame('13', $report->otrs_last_article_id);
    }

    public function test_html_article_body_is_reduced_to_text(): void
    {
        $this->configureOtrs();
        $this->fakeTicketGet([
            ['ArticleID' => '10', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1,
                'Body' => '<p>Hallo &amp; Tsch&uuml;ss</p>', 'ContentType' => 'text/html; charset=utf8'],
        ]);
        $report = $this->ticketedReport();

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $this->assertSame('Hallo & Tschüss', $report->refresh()->messages->first()?->content);
    }

    public function test_imported_answer_is_not_pushed_back_into_otrs(): void
    {
        $this->configureOtrs();
        $this->fakeTicketGet([
            ['ArticleID' => '10', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'answer'],
        ]);
        $report = $this->ticketedReport();

        // Queue runs sync in tests, so the notification fan-out (incl. the OTRS
        // channel) executes inline. The echo guard must keep it from writing.
        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        $this->assertCount(1, $report->refresh()->messages);
        Http::assertNotSent(fn (ClientRequest $r): bool => in_array($r->method(), ['POST', 'PATCH'], true));
    }

    public function test_emails_reporter_when_a_contact_address_was_left(): void
    {
        $this->configureOtrs();
        Mail::fake();
        $this->fakeTicketGet([
            ['ArticleID' => '10', 'SenderType' => 'agent', 'IsVisibleForCustomer' => 1, 'Body' => 'answer'],
        ]);
        $report = $this->ticketedReport(creator: 'reporter@example.org');

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        // ReportNotification is ShouldQueue, so Mail::fake records it as queued.
        Mail::assertQueued(ReportNotification::class, fn (ReportNotification $m): bool => $m->hasTo('reporter@example.org'));
    }

    public function test_no_op_when_inbound_disabled(): void
    {
        $this->configureOtrs(['inbound_enabled' => false]);
        Http::fake();
        $report = $this->ticketedReport();

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        Http::assertNothingSent();
        $this->assertCount(0, $report->refresh()->messages);
    }

    public function test_done_reports_are_not_polled(): void
    {
        $this->configureOtrs();
        Http::fake(['*' => Http::response(['Ticket' => [['Article' => []]]], 200)]);
        $report = $this->ticketedReport();
        $report->update(['state' => ReportState::Done]);

        $this->assertSame(0, Artisan::call('otrs:poll-replies'));

        // A concluded report's conversation is over — its ticket is never read.
        Http::assertNothingSent();
    }
}
