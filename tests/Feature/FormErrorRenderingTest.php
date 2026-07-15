<?php

namespace Tests\Feature;

use App\Http\Requests\SubmitReportRequest;
use App\Models\Field;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * A rejected submission has to tell the reporter *which* field failed. A Files
 * field registers rules on both the bare key ({id}) and the per-file key
 * ({id}.{index}), so hardcoding the view's lookup to {id}.0 silently swallowed
 * the error for every file but the first.
 *
 * Note on method: these tests do not drive uploads through $this->post(). Laravel's
 * test client merges extracted files with array_merge(), which renumbers integer
 * keys — and this app names file inputs after the numeric field ID, so a file
 * posted as `14` arrives as `0` and the field reads as absent. That is an artifact
 * of the test harness only (real multipart requests populate $_FILES directly), but
 * it makes HTTP-level upload assertions pass for the wrong reason. So the rules are
 * checked against the validator directly, and the view is checked against seeded
 * error bags.
 */
class FormErrorRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function filesField(bool $required = false): Field
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);

        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Attachments', 'name_en' => 'Attachments',
            'type' => 'files',
            'required' => $required,
            'position' => 0,
        ]);
    }

    /**
     * The real rule set the controller would apply for this topic.
     *
     * @return array<array-key, mixed>
     */
    private function rulesFor(Field $field): array
    {
        return SubmitReportRequest::create('/submit', 'POST', ['topic' => $field->topic_id])->rules();
    }

    /**
     * Render the reporter form with $messages already in the session error bag.
     *
     * @param array<array-key, list<string>> $messages a numeric field name coerces to an int key
     */
    private function renderFormWithErrors(Field $field, array $messages): string
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag($messages));

        return (string) $this->withSession(['errors' => $bag])
            ->get(route('form.show', ['topic' => $field->topic_id]))
            ->assertOk()
            ->getContent();
    }

    public function test_a_disallowed_second_file_reports_under_its_own_index(): void
    {
        $field = $this->filesField();
        $name = (string) $field->id;

        $validator = Validator::make([
            'topic' => $field->topic_id,
            $name => [
                UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream'),
            ],
        ], $this->rulesFor($field));

        $this->assertTrue($validator->fails(), 'the extension allowlist did not reject evil.exe');
        // The error keys on the index, not the bare field — which is exactly why
        // a hardcoded `{id}.0` lookup missed it.
        $this->assertSame([$name.'.1'], $validator->errors()->keys());
    }

    public function test_form_renders_an_error_reported_on_a_later_file(): void
    {
        $field = $this->filesField();

        $html = $this->renderFormWithErrors($field, [
            (string) $field->id.'.1' => ['The second file is not allowed.'],
        ]);

        $this->assertStringContainsString('The second file is not allowed.', $html);
    }

    public function test_form_renders_an_error_reported_on_the_first_file(): void
    {
        $field = $this->filesField();

        $html = $this->renderFormWithErrors($field, [
            (string) $field->id.'.0' => ['The first file is not allowed.'],
        ]);

        $this->assertStringContainsString('The first file is not allowed.', $html);
    }

    public function test_form_renders_an_error_reported_on_the_bare_field_key(): void
    {
        // A required Files field left empty errors on `{id}`, not `{id}.N`.
        $field = $this->filesField(required: true);

        $html = $this->renderFormWithErrors($field, [
            (string) $field->id => ['Attachments are required.'],
        ]);

        $this->assertStringContainsString('Attachments are required.', $html);
    }

    public function test_required_files_field_left_empty_reports_on_the_bare_key(): void
    {
        $field = $this->filesField(required: true);

        $validator = Validator::make(['topic' => $field->topic_id], $this->rulesFor($field));

        $this->assertTrue($validator->fails());
        // Loose compare: a numeric field name comes back as an int array key.
        $this->assertEquals([$field->id], $validator->errors()->keys());
    }

    public function test_allowed_files_pass_validation(): void
    {
        $field = $this->filesField();
        $name = (string) $field->id;

        $validator = Validator::make([
            'topic' => $field->topic_id,
            $name => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.png', 10, 'image/png'),
            ],
        ], $this->rulesFor($field));

        $this->assertFalse($validator->fails(), 'allowed files were rejected: '.$validator->errors());
    }

    public function test_form_renders_no_field_error_when_the_session_is_clean(): void
    {
        $field = $this->filesField();

        $html = (string) $this->get(route('form.show', ['topic' => $field->topic_id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('field-error', $html);
    }
}
