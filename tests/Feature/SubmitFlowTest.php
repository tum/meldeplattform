<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame('open', $report->state);
        $this->assertCount(1, $report->messages);
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

        $this->post('/submit', [
            'topic' => $topic->id,
            (string) $field->id => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
        ])->assertStatus(400);
    }
}
