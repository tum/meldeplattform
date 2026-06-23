<?php

namespace Tests\Unit;

use App\Enums\FieldType;
use App\Models\Field;
use Tests\TestCase;

class FieldTest extends TestCase
{
    public function test_only_info_is_display_only(): void
    {
        $this->assertTrue(FieldType::Info->isDisplayOnly());

        foreach (FieldType::cases() as $type) {
            if ($type !== FieldType::Info) {
                $this->assertFalse($type->isDisplayOnly(), "{$type->value} must not be display-only");
            }
        }
    }

    public function test_rendered_description_applies_markdown_and_brand_colours(): void
    {
        // An Info field's content runs through the same pipeline as topic
        // summaries: markdown formatting plus the closed brand-colour palette.
        $field = new Field;
        $field->description_de = '**fett** und {green}ok{/green}';
        $field->description_en = '**bold** and {red}stop{/red}';

        $de = $field->renderedDescription('de');
        $this->assertStringContainsString('<strong>fett</strong>', $de);
        $this->assertStringContainsString('<span class="t-color-green">ok</span>', $de);

        $en = $field->renderedDescription('en');
        $this->assertStringContainsString('<strong>bold</strong>', $en);
        $this->assertStringContainsString('<span class="t-color-red">stop</span>', $en);
    }

    public function test_rendered_description_neutralises_html(): void
    {
        $field = new Field;
        $field->description_de = '<script>alert(1)</script>';

        $html = $field->renderedDescription('de');
        $this->assertStringNotContainsString('<script', $html);
    }
}
