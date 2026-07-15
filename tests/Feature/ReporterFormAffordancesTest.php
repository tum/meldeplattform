<?php

namespace Tests\Feature;

use App\Models\Field;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporter-facing input affordances that are easy to break silently, because
 * nothing fails server-side when they are wrong — the page just becomes harder
 * (or impossible) to use on the devices real reporters are on.
 */
class ReporterFormAffordancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_code_input_does_not_force_a_numeric_keypad(): void
    {
        // Receipt codes are uppercase hex (issueReceiptCode), so they contain
        // A-F: a numeric inputmode leaves mobile reporters unable to type them.
        $html = (string) $this->get(route('report.track'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="code"[^>]*>/', $html);
        preg_match('/<input[^>]*name="code"[^>]*>/', $html, $tag);
        $this->assertStringNotContainsString('inputmode="numeric"', $tag[0] ?? '');
    }

    public function test_audio_recorder_status_labels_are_localised(): void
    {
        // audio-recorder.js reads these attributes and falls back to hardcoded
        // English when they are absent.
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Sprachnachricht', 'name_en' => 'Voice message',
            'type' => 'audio',
            'required' => false,
            'position' => 0,
        ]);

        // LocaleMiddleware resolves the language from the `lang` cookie, else
        // Accept-Language.
        $html = (string) $this->withHeader('Accept-Language', 'de')
            ->get(route('form.show', ['topic' => $topic->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-recording-label="'.__('audio_recording', [], 'de').'"', $html);
        $this->assertStringContainsString('data-denied-label="'.__('audio_denied', [], 'de').'"', $html);
    }
}
