<?php

namespace Tests\Feature;

use App\Http\Requests\SubmitReportRequest;
use App\Models\Field;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The declared FieldType must be enforced server-side, not just as an HTML5
 * input hint a client can bypass.
 */
class FieldValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param list<string>|null $choices
     */
    private function topicWithField(string $type, bool $required = false, ?array $choices = null): Field
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);

        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => $type,
            'required' => $required,
            'choices' => $choices,
            'position' => 0,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function submit(Field $field, mixed $value): TestResponse
    {
        return $this->postJson('/submit', [
            'topic' => $field->topic_id,
            (string) $field->id => $value,
        ]);
    }

    public function test_email_field_rejects_non_email(): void
    {
        $field = $this->topicWithField('email');
        $this->submit($field, 'not-an-email')->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }

    public function test_email_field_accepts_email(): void
    {
        $field = $this->topicWithField('email');
        $this->submit($field, 'who@example.com')->assertRedirect();
    }

    public function test_number_field_rejects_non_numeric(): void
    {
        $field = $this->topicWithField('number');
        $this->submit($field, 'abc')->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }

    public function test_url_field_rejects_non_url(): void
    {
        $field = $this->topicWithField('url');
        $this->submit($field, 'not a url')->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }

    public function test_date_field_rejects_non_date(): void
    {
        $field = $this->topicWithField('date');
        $this->submit($field, 'yesterday-ish')->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }

    public function test_select_field_rejects_value_outside_choices(): void
    {
        $field = $this->topicWithField('select', choices: ['low', 'high']);
        $this->submit($field, 'critical')->assertStatus(422)->assertJsonValidationErrors([(string) $field->id]);
    }

    public function test_select_field_accepts_configured_choice(): void
    {
        $field = $this->topicWithField('select', choices: ['low', 'high']);
        $this->submit($field, 'high')->assertRedirect();
    }

    public function test_choiceless_select_accepts_any_string(): void
    {
        $field = $this->topicWithField('select', choices: []);
        $this->submit($field, 'anything')->assertRedirect();
    }

    public function test_nullable_typed_field_allows_empty(): void
    {
        // A non-required email field must not reject an empty submission.
        $field = $this->topicWithField('email', required: false);
        $this->submit($field, '')->assertRedirect();
    }

    public function test_audio_field_accepts_audio_but_rejects_other_allowed_types(): void
    {
        // Verify the real rules SubmitReportRequest builds for an audio field:
        // an audio upload passes, while a PDF — allowed for general file fields
        // but NOT on the audio allowlist — is rejected. Exercised through an
        // in-process Validator because the test HTTP client does not carry
        // multipart uploads in this environment.
        $field = $this->topicWithField('audio', required: true);
        $key = (string) $field->id;

        $request = SubmitReportRequest::create('/submit', 'POST', ['topic' => $field->topic_id]);
        $request->setContainer($this->app);
        $rules = $request->rules();
        $this->assertArrayHasKey($key, $rules);

        $mp3 = UploadedFile::fake()->create('voice-message.mp3', 100, 'audio/mpeg');
        $this->assertTrue(
            Validator::make([$key => $mp3], [$key => $rules[$key]])->passes(),
            'audio upload should pass the audio field rules',
        );

        $pdf = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');
        $this->assertTrue(
            Validator::make([$key => $pdf], [$key => $rules[$key]])->fails(),
            'PDF should be rejected by the audio field rules',
        );
    }
}
