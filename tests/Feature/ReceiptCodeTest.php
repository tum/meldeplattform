<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptCodeTest extends TestCase
{
    use RefreshDatabase;

    private function receiptCode(): string
    {
        $code = session('receipt_code');

        return is_string($code) ? $code : '';
    }

    private function makeTopicWithField(): Field
    {
        $topic = Topic::create([
            'name_de' => 'Test',
            'name_en' => 'Test',
            'summary_de' => 's',
            'summary_en' => 's',
            'email' => 'it-sec@tum.de',
        ]);

        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Frage',
            'name_en' => 'Question',
            'type' => 'textarea',
            'required' => true,
            'position' => 0,
        ]);
    }

    public function test_submit_stores_hash_and_flashes_plain_code(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $field = $this->makeTopicWithField();

        $response = $this->post('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => 'Das ist ein Meldetext.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('receipt_code');

        $code = $this->receiptCode();
        // 24 uppercase hex characters (12 random bytes = 96 bits entropy).
        $this->assertMatchesRegularExpression('/^[0-9A-F]{24}$/', $code);

        $report = Report::first();
        $this->assertNotNull($report);
        $this->assertNotNull($report->receipt_hash);

        // The stored value must be the HMAC, never the plaintext code.
        $this->assertNotSame($code, $report->receipt_hash);
        $this->assertSame(64, strlen((string) $report->receipt_hash));
    }

    public function test_correct_code_redirects_to_report_and_grants_access(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $field = $this->makeTopicWithField();

        $this->post('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => 'Geheime Meldung.',
        ]);

        $code = $this->receiptCode();
        $report = Report::firstOrFail();

        $response = $this->post('/track', ['code' => $code]);

        $response->assertRedirect(route('report.show', ['reporterToken' => $report->reporter_token]));

        // The reporter can then actually view the report.
        $this->get(route('report.show', ['reporterToken' => $report->reporter_token]))
            ->assertOk();
    }

    public function test_correct_code_works_with_spaces(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $field = $this->makeTopicWithField();

        $this->post('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => 'Meldung.',
        ]);

        $code = $this->receiptCode();
        $report = Report::firstOrFail();

        $spaced = implode(' ', str_split($code, 4));

        $this->post('/track', ['code' => $spaced])
            ->assertRedirect(route('report.show', ['reporterToken' => $report->reporter_token]));
    }

    public function test_wrong_code_returns_error_and_does_not_grant_access(): void
    {
        $this->makeTopicWithField();

        $this->from(route('report.track'))
            ->post('/track', ['code' => '0000000000000000'])
            ->assertRedirect(route('report.track'))
            ->assertSessionHasErrors(['code']);
    }

    public function test_excessively_long_code_is_rejected_by_validation(): void
    {
        $this->from(route('report.track'))
            ->post('/track', ['code' => str_repeat('1', 101)])
            ->assertRedirect(route('report.track'))
            ->assertSessionHasErrors(['code']);
    }

    public function test_code_with_only_non_hex_chars_does_not_match(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $field = $this->makeTopicWithField();

        $this->post('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => 'Meldung.',
        ]);

        // Non-hex characters normalize to an empty string, so findByReceiptCode
        // returns null without hitting the database.
        $this->from(route('report.track'))
            ->post('/track', ['code' => '!@#$%^&*()'])
            ->assertRedirect(route('report.track'))
            ->assertSessionHasErrors(['code']);
    }

    public function test_code_with_spaces_between_hex_groups_is_accepted(): void
    {
        Mail::fake();
        Storage::fake('uploads');

        $field = $this->makeTopicWithField();

        $this->post('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => 'Meldung.',
        ]);

        $code = $this->receiptCode();
        $report = Report::firstOrFail();

        // Simulate user grouping the 24-char hex code in blocks of 4.
        $spaced = implode(' ', str_split($code, 4));

        $this->post('/track', ['code' => $spaced])
            ->assertRedirect(route('report.show', ['reporterToken' => $report->reporter_token]));
    }
}
