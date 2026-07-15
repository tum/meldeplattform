<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Http\Requests\SubmitReportRequest;
use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SubmitFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_page_requires_existing_topic(): void
    {
        $this->get('/form/999')->assertNotFound();
    }

    public function test_form_page_renders_for_existing_topic(): void
    {
        $topic = Topic::create([
            'name_de' => 'IT-Sicherheit',
            'name_en' => 'IT Security',
            'summary_de' => 'Probleme',
            'summary_en' => 'Issues',
        ]);
        Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Beschreibung',
            'name_en' => 'Description',
            'type' => 'textarea',
            'required' => true,
            'position' => 0,
        ]);

        $this->get("/form/{$topic->id}")
            ->assertOk()
            ->assertSee('IT Security')
            ->assertSee('Description');
    }

    public function test_info_field_renders_as_formatted_html_without_input(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $info = Field::create([
            'topic_id' => $topic->id,
            'name_de' => '', 'name_en' => '',
            'description_de' => '**Hinweis** {green}wichtig{/green}',
            'description_en' => '**Notice** {green}important{/green}',
            'type' => 'info',
            'required' => false,
            'position' => 0,
        ]);

        // The form defaults to the English locale (no lang cookie set).
        $this->get("/form/{$topic->id}")
            ->assertOk()
            // Rendered with the same markdown + brand-colour pipeline as summaries.
            ->assertSee('<strong>Notice</strong>', escape: false)
            ->assertSee('<span class="t-color-green">important</span>', escape: false)
            // Display-only: no input control and no label wired to one.
            ->assertDontSee('name="'.$info->id.'"', escape: false)
            ->assertDontSee('field-'.$info->id, escape: false);
    }

    public function test_info_field_is_excluded_from_report_body(): void
    {
        Mail::fake();

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        Field::create([
            'topic_id' => $topic->id,
            'name_de' => '', 'name_en' => '',
            'description_de' => 'INFOTEXT-DE', 'description_en' => 'INFOTEXT-EN',
            'type' => 'info',
            'required' => false,
            'position' => 0,
        ]);
        $question = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Frage', 'name_en' => 'Question',
            'type' => 'textarea',
            'required' => true,
            'position' => 1,
        ]);

        // No value is posted for the Info field — it is display-only and must
        // not be required, so the submission still succeeds.
        $this->post('/submit', [
            'topic' => $topic->id,
            (string) $question->id => 'Antwort',
        ])->assertRedirect();

        $body = (string) Report::first()?->messages->first()?->content;
        $this->assertStringContainsString('Antwort', $body);
        $this->assertStringNotContainsString('INFOTEXT', $body);
    }

    public function test_submit_creates_report_and_redirects_with_token(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $topic = Topic::create([
            'name_de' => 'Test',
            'name_en' => 'Test',
            'summary_de' => 's',
            'summary_en' => 's',
            'email' => 'it-sec@tum.de',
        ]);
        $field = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Frage',
            'name_en' => 'Question',
            'type' => 'textarea',
            'required' => true,
            'position' => 0,
        ]);

        $response = $this->post('/submit', [
            'topic' => $topic->id,
            'email' => 'anon@example.com',
            (string) $field->id => 'Das ist ein Meldetext.',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/report?reporterToken=', (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('reports', 1);

        $report = Report::first();
        $this->assertNotNull($report);
        $this->assertSame('anon@example.com', $report->creator);
        $this->assertSame(ReportState::Open, $report->state);
        $this->assertCount(1, $report->messages);
    }

    public function test_submit_renders_date_field_as_german_date(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $topic = Topic::create([
            'name_de' => 'Test', 'name_en' => 'Test',
            'summary_de' => 's', 'summary_en' => 's',
        ]);
        $field = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Datum',
            'name_en' => 'Date',
            'type' => 'date',
            'required' => true,
            'position' => 0,
        ]);

        // <input type="date"> always submits the ISO yyyy-mm-dd wire format.
        $this->post('/submit', [
            'topic' => $topic->id,
            (string) $field->id => '2026-03-05',
        ])->assertRedirect();

        $report = Report::first();
        $this->assertNotNull($report);
        $body = (string) $report->messages->first()?->content;
        $this->assertStringContainsString('05.03.2026', $body);
        $this->assertStringNotContainsString('2026-03-05', $body);
    }

    public function test_submit_rejects_bad_email(): void
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'textarea', 'required' => false, 'position' => 0,
        ]);

        $this->postJson('/submit', [
            'topic' => $topic->id,
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    public function test_submit_rejects_unknown_topic(): void
    {
        $this->postJson('/submit', [
            'topic' => 9999,
        ])->assertStatus(422);
    }

    /**
     * The allowlist is checked against the validator rather than over HTTP on
     * purpose. Laravel's test client merges extracted uploads with array_merge(),
     * which renumbers integer keys — and file inputs here are named after the
     * numeric field ID, so a file posted as `14` arrives as `0` and the field
     * reads as absent. Driven over HTTP this test still went red, but on
     * "the 14 field is required" rather than on the extension: it would have
     * passed just as happily with the allowlist deleted. The artifact is confined
     * to the test harness (real multipart requests populate $_FILES directly).
     */
    public function test_submit_honours_upload_extension_allowlist(): void
    {
        Storage::fake('uploads');

        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $field = Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'file', 'required' => true, 'position' => 0,
        ]);
        $name = (string) $field->id;
        $rules = SubmitReportRequest::create('/submit', 'POST', ['topic' => $topic->id])->rules();

        $rejected = Validator::make([
            'topic' => $topic->id,
            $name => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
        ], $rules);

        $this->assertTrue($rejected->fails(), 'evil.exe passed the extension allowlist');
        $this->assertStringNotContainsString(
            'required',
            (string) $rejected->errors()->first($name),
            'the file never reached validation, so the allowlist was not exercised',
        );

        // Control: an allowlisted file of the same size passes, proving the
        // rejection above is about the extension and nothing else.
        $accepted = Validator::make([
            'topic' => $topic->id,
            $name => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ], $rules);

        $this->assertFalse($accepted->fails(), 'an allowlisted file was rejected: '.$accepted->errors());
    }

    public function test_form_renders_inline_validation_errors_after_redirect_back(): void
    {
        // Regression: a bad-email submit redirects back with errors in
        // session — those need to surface as `.field-error` spans on the
        // form so the reporter knows what to fix.
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'textarea', 'required' => false, 'position' => 0,
        ]);

        $this->from("/form/{$topic->id}")
            ->post('/submit', ['topic' => $topic->id, 'email' => 'not-an-email'])
            ->assertRedirect("/form/{$topic->id}")
            ->assertSessionHasErrors(['email']);

        $this->withSession(['errors' => session('errors')])
            ->get("/form/{$topic->id}")
            ->assertOk()
            ->assertSee('field-error', escape: false);
    }
}
