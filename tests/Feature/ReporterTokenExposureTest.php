<?php

namespace Tests\Feature;

use App\Actions\StoreReportSubmission;
use App\Http\Requests\SubmitReportRequest;
use App\Models\Field;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Support\AttachmentLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The reporter_token is the anonymous reporter's whole credential: it opens
 * their report and lets its holder post messages *as them*. It must therefore
 * never be written into the report body, which is read by every case handler
 * and — for topics routing to OTRS — copied verbatim into a ticket that a whole
 * queue of agents can read and that outlives the report's retention window.
 */
class ReporterTokenExposureTest extends TestCase
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

    /** Submit one report carrying a single upload, through the real action. */
    private function reportWithUpload(Topic $topic): Report
    {
        $field = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Beleg', 'name_en' => 'Evidence',
            'type' => 'file', 'required' => false, 'position' => 0,
        ]);

        $request = SubmitReportRequest::create('/submit', 'POST', ['topic' => $topic->id], [], [
            (string) $field->id => UploadedFile::fake()->create('kuendigung.pdf', 10, 'application/pdf'),
        ]);
        $request->setContainer($this->app);
        $request->setUserResolver(static fn () => null);

        return app(StoreReportSubmission::class)->execute($request);
    }

    private function topic(): Topic
    {
        return Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
    }

    public function test_stored_message_body_carries_no_reporter_token(): void
    {
        Storage::fake('uploads');

        $report = $this->reportWithUpload($this->topic());
        $body = (string) $report->messages()->first()?->content;

        $this->assertStringContainsString('/file/', $body, 'the upload link is missing entirely');
        $this->assertStringNotContainsString('token=', $body);
        $this->assertStringNotContainsString($report->reporter_token, $body);
    }

    public function test_reporter_view_stitches_their_token_into_the_attachment_link(): void
    {
        Storage::fake('uploads');

        $report = $this->reportWithUpload($this->topic());

        $html = (string) $this->get('/report?reporterToken='.$report->reporter_token)->getContent();

        $this->assertStringContainsString('class="attachment attachment-pdf"', $html);
        $this->assertStringContainsString('token='.$report->reporter_token, $html);
    }

    public function test_admin_view_never_renders_the_reporter_token(): void
    {
        Storage::fake('uploads');

        $topic = $this->topic();
        $report = $this->reportWithUpload($topic);
        $admin = User::create(['uid' => 'ga', 'name' => 'GA', 'is_global_admin' => true]);

        $html = (string) $this->actingAs($admin)
            ->get('/reports/'.$topic->id.'/'.$report->id)
            ->getContent();

        // The admin still gets a working card — their own session authorises the
        // download — but not the reporter's credential.
        $this->assertStringContainsString('class="attachment attachment-pdf"', $html);
        $this->assertStringNotContainsString($report->reporter_token, $html);
    }

    public function test_otrs_article_carries_no_reporter_token(): void
    {
        Storage::fake('uploads');
        Http::fake(['*' => Http::response(['TicketID' => '7', 'TicketNumber' => '70'], 200)]);
        config(['meldeplattform.otrs' => self::OTRS_CONFIG]);

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
            'contacts' => ['otrs' => []],
        ]);
        $report = $this->reportWithUpload($topic);

        Http::assertSent(function (ClientRequest $request) use ($report): bool {
            $this->assertStringNotContainsString(
                $report->reporter_token,
                (string) json_encode($request->data()),
                'the OTRS ticket was handed the reporter access token',
            );

            return true;
        });
    }

    public function test_legacy_bodies_still_render_a_working_card_for_the_reporter(): void
    {
        Storage::fake('uploads');

        $topic = $this->topic();
        $report = Report::create(['topic_id' => $topic->id]);
        Storage::disk('uploads')->put('attachment-aaaaaaaa.pdf', 'x');
        $file = File::create(['path' => 'attachment-aaaaaaaa.pdf', 'name' => 'attachment-aaaaaaaa.pdf']);

        // A body written before the token was dropped, still carrying it.
        $message = Message::create([
            'report_id' => $report->id,
            'content' => '[a]('.route('file.download', [
                'name' => $file->name, 'id' => $file->uuid, 'token' => $report->reporter_token,
            ]).')',
            'is_admin' => false,
        ]);
        $message->files()->sync([$file->id]);

        // Rendered for an administrator, the href is rebuilt from the File row,
        // so the stale token in the stored body never reaches the page.
        $this->assertStringNotContainsString(
            $report->reporter_token,
            $message->load('files')->renderedBody(),
        );
    }

    public function test_strip_reporter_tokens_cleans_links_without_touching_reporter_prose(): void
    {
        $token = 'caa9b14c-2488-4134-82ad-4ca34df1f0c2';
        $body = "[a](https://app.test/file/attachment-1.pdf?id=u-1&token={$token})\n"
            ."[b](https://app.test/file/attachment-2.pdf?token={$token}&id=u-2)\n"
            .'[mine](https://example.org/dashboard?token=my-own-token)';

        $stripped = AttachmentLinks::stripReporterTokens($body);

        $this->assertStringNotContainsString($token, $stripped);
        $this->assertStringContainsString('https://app.test/file/attachment-1.pdf?id=u-1', $stripped);
        $this->assertStringContainsString('https://app.test/file/attachment-2.pdf?id=u-2', $stripped);
        // A link the reporter typed themselves is content, not an attachment.
        $this->assertStringContainsString('https://example.org/dashboard?token=my-own-token', $stripped);
    }

    public function test_the_backfill_migration_cleans_bodies_written_before_the_fix(): void
    {
        Storage::fake('uploads');

        $topic = $this->topic();
        $report = Report::create(['topic_id' => $topic->id]);
        $file = File::create(['path' => 'attachment-bbbbbbbb.pdf', 'name' => 'attachment-bbbbbbbb.pdf']);
        $legacy = '[a]('.route('file.download', [
            'name' => $file->name, 'id' => $file->uuid, 'token' => $report->reporter_token,
        ]).')';

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $legacy,
            'is_admin' => false,
        ]);
        $touchedAt = $report->fresh()?->updated_at;

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_30_000000_strip_reporter_tokens_from_message_bodies.php');
        $migration->up();

        $content = (string) $message->fresh()?->content;
        $this->assertStringNotContainsString($report->reporter_token, $content);
        $this->assertStringContainsString('id='.$file->uuid, $content);
        // A data cleanup must not re-surface every report as unread.
        $this->assertEquals($touchedAt, $report->fresh()?->updated_at);
    }

    public function test_download_still_authorises_for_both_the_reporter_and_an_admin(): void
    {
        Storage::fake('uploads');

        $topic = $this->topic();
        $report = $this->reportWithUpload($topic);
        $file = File::firstOrFail();
        $admin = User::create(['uid' => 'ga2', 'name' => 'GA', 'is_global_admin' => true]);

        $this->get('/file/'.$file->name.'?id='.$file->uuid.'&token='.$report->reporter_token)
            ->assertOk();

        // Still closed to an anonymous visitor who holds no token.
        $this->get('/file/'.$file->name.'?id='.$file->uuid)->assertForbidden();

        $this->actingAs($admin)
            ->get('/file/'.$file->name.'?id='.$file->uuid)
            ->assertOk();
    }
}
