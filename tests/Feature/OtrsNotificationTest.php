<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use App\Services\Messengers\OtrsMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OtrsNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int|string> */
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
    ];

    /** @param array<string, int|string> $overrides */
    private function configureOtrs(array $overrides = []): void
    {
        config(['meldeplattform.otrs' => array_merge(self::OTRS_CONFIG, $overrides)]);
    }

    /** @param array<string, string> $otrsContacts */
    private function otrsTopic(array $otrsContacts = []): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['otrs' => $otrsContacts],
        ]);
    }

    public function test_topic_with_otrs_contact_yields_an_otrs_messenger(): void
    {
        $this->configureOtrs();
        $topic = $this->otrsTopic(['queue' => 'Whistleblowing']);

        $messengers = app(MessengerDispatcher::class)->forTopic($topic);

        $this->assertCount(1, $messengers);
        $this->assertInstanceOf(OtrsMessenger::class, $messengers[0]);
    }

    public function test_otrs_routing_is_skipped_when_connection_not_configured(): void
    {
        config(['meldeplattform.otrs' => ['base_url' => '', 'user_login' => '', 'password' => '']]);
        $topic = $this->otrsTopic(['queue' => 'X']);

        $this->assertCount(0, app(MessengerDispatcher::class)->forTopic($topic));
    }

    public function test_first_message_creates_a_ticket_with_full_content_and_persists_id(): void
    {
        $this->configureOtrs();
        Http::fake([
            '*/Ticket' => Http::response(
                ['TicketID' => '4242', 'TicketNumber' => '20260600001', 'ArticleID' => '7'],
                200,
            ),
        ]);

        $topic = $this->otrsTopic(['queue' => 'Whistleblowing']);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'SECRET-allegation-text', 'is_admin' => false,
        ]);

        app(MessengerDispatcher::class)
            ->sendNow($topic, '[T]: report #1 opened', $message, 'https://app/report');

        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();
            $json = (string) json_encode($data);

            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/Ticket')
                && ($data['UserLogin'] ?? null) === 'svc'
                && ($data['Ticket']['Queue'] ?? null) === 'Whistleblowing'
                // Internal article: report content must never be customer-visible.
                && ($data['Article']['IsVisibleForCustomer'] ?? null) === 0
                // Unlike email/webhook, the report content IS carried into OTRS.
                && str_contains($json, 'SECRET-allegation-text')
                && str_contains((string) ($data['Article']['Body'] ?? ''), 'https://app/report');
        });

        $report->refresh();
        $this->assertSame('4242', $report->otrs_ticket_id);
        $this->assertSame('20260600001', $report->otrs_ticket_number);
    }

    public function test_later_message_updates_the_existing_ticket(): void
    {
        $this->configureOtrs();
        Http::fake(['*' => Http::response(['ArticleID' => '8'], 200)]);

        $topic = $this->otrsTopic(['queue' => 'Whistleblowing']);
        $report = Report::create(['topic_id' => $topic->id]);
        $report->forceFill(['otrs_ticket_id' => '4242'])->save();
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'a reply', 'is_admin' => true,
        ]);

        app(MessengerDispatcher::class)
            ->sendNow($topic, '[T]: report #1 updated', $message, 'https://app/report');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/Ticket/4242')
            && ($request->data()['TicketID'] ?? null) === '4242');
        // No second ticket was opened for a follow-up message.
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST');
    }

    public function test_non_https_endpoint_sends_nothing(): void
    {
        $this->configureOtrs(['base_url' => 'http://otrs.test/otrs/nph-genericinterface.pl/Webservice/GTC']);
        Http::fake();

        $topic = $this->otrsTopic(['queue' => 'X']);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'secret', 'is_admin' => false,
        ]);

        app(MessengerDispatcher::class)->sendNow($topic, 'Title', $message, 'https://app/report');

        Http::assertNothingSent();
        $report->refresh();
        $this->assertNull($report->otrs_ticket_id);
    }

    public function test_otrs_application_error_with_http_200_bubbles_up_for_retry(): void
    {
        $this->configureOtrs();
        Http::fake([
            '*' => Http::response(
                ['Error' => ['ErrorCode' => 'TicketCreate.AuthFail', 'ErrorMessage' => 'nope']],
                200,
            ),
        ]);

        $topic = $this->otrsTopic(['queue' => 'X']);
        $report = Report::create(['topic_id' => $topic->id]);
        $message = Message::create([
            'report_id' => $report->id, 'content' => 'secret', 'is_admin' => false,
        ]);

        $this->expectException(RuntimeException::class);

        app(MessengerDispatcher::class)->sendNow($topic, 'Title', $message, 'https://app/report');
    }
}
