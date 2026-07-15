<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Checkbox field must survive the round trip a real browser makes. These
 * tests deliberately submit the value taken from the *rendered markup* rather
 * than a hand-picked one: the bug they guard against was a checkbox with no
 * `value` attribute, which browsers submit as the literal "on" — a value the
 * field's `boolean` rule rejects. Every test here passed when the payload was
 * written by hand, which is exactly why the defect shipped.
 */
class CheckboxFieldSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function checkboxTopic(bool $required = false): Field
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);

        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'checkbox',
            'required' => $required,
            'position' => 0,
        ]);
    }

    /**
     * The value a browser would submit for this checkbox when ticked: its
     * `value` attribute, or "on" when it has none (the HTML default).
     */
    private function tickedValue(Field $field): string
    {
        $html = (string) $this->get(route('form.show', ['topic' => $field->topic_id]))
            ->assertOk()
            ->getContent();

        $pattern = '/<input type="checkbox"[^>]*name="'.preg_quote((string) $field->id, '/').'"[^>]*>/';
        $this->assertMatchesRegularExpression($pattern, $html, 'checkbox input was not rendered');
        preg_match($pattern, $html, $tag);

        return preg_match('/value="([^"]*)"/', $tag[0], $v) === 1 ? $v[1] : 'on';
    }

    public function test_checkbox_renders_an_explicit_value_attribute(): void
    {
        $field = $this->checkboxTopic();

        // Without this, browsers post "on" and the `boolean` rule rejects it.
        $this->assertSame('1', $this->tickedValue($field));
    }

    public function test_ticking_a_checkbox_submits_the_report(): void
    {
        $field = $this->checkboxTopic();

        $this->post(route('form.submit'), [
            'topic' => $field->topic_id,
            (string) $field->id => $this->tickedValue($field),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Report::count());
    }

    public function test_topic_with_a_required_checkbox_can_receive_a_report(): void
    {
        // The regression this guards: a *required* checkbox bricked the topic
        // outright — unticked failed `required`, ticked failed `boolean`.
        $field = $this->checkboxTopic(required: true);

        $this->post(route('form.submit'), [
            'topic' => $field->topic_id,
            (string) $field->id => $this->tickedValue($field),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Report::count());
    }

    public function test_required_checkbox_still_rejects_an_unticked_box(): void
    {
        // An unticked checkbox is simply absent from the payload.
        $field = $this->checkboxTopic(required: true);

        $this->post(route('form.submit'), ['topic' => $field->topic_id])
            ->assertSessionHasErrors((string) $field->id);

        $this->assertSame(0, Report::count());
    }
}
