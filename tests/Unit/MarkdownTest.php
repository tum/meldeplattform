<?php

namespace Tests\Unit;

use App\Support\Markdown;
use Tests\TestCase;

class MarkdownTest extends TestCase
{
    public function test_sanitize_renders_basic_markdown(): void
    {
        $html = Markdown::sanitize('**hello** world');
        $this->assertStringContainsString('<strong>hello</strong>', $html);
        $this->assertStringContainsString('world', $html);
    }

    public function test_sanitize_strips_script_tags(): void
    {
        // Opening <script> tag is escaped to &lt;script&gt; — the point is
        // that no real script element survives the sanitizer.
        $html = Markdown::sanitize('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script', $html);
    }

    public function test_sanitize_strips_onclick_attribute(): void
    {
        $html = Markdown::sanitize('[click](http://example.com "onclick=alert(1)")');
        $this->assertStringNotContainsString(' onclick=', $html);
    }

    public function test_sanitize_keeps_safe_links(): void
    {
        $html = Markdown::sanitize('[link](https://example.com)');
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_sanitize_blocks_javascript_urls(): void
    {
        $html = Markdown::sanitize('[x](javascript:alert(1))');
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_operator_markdown_allows_headings(): void
    {
        $html = Markdown::renderOperatorContent("# Heading\n\ntext");
        $this->assertStringContainsString('<h1>Heading</h1>', $html);
    }

    public function test_color_shortcodes_render_palette_spans(): void
    {
        $html = Markdown::sanitizeWithColors('Status: {green}done{/green} and {red}urgent{/red}');
        $this->assertStringContainsString('<span class="t-color-green">done</span>', $html);
        $this->assertStringContainsString('<span class="t-color-red">urgent</span>', $html);
        $this->assertStringNotContainsString('{green}', $html);
    }

    public function test_color_shortcodes_compose_with_markdown(): void
    {
        $html = Markdown::sanitizeWithColors('{blue}**bold**{/blue}');
        $this->assertStringContainsString('<span class="t-color-blue"><strong>bold</strong></span>', $html);
    }

    public function test_unknown_color_is_left_as_literal_text(): void
    {
        // Only the fixed palette is recognised; anything else stays inert text.
        $html = Markdown::sanitizeWithColors('{purple}x{/purple}');
        $this->assertStringNotContainsString('<span', $html);
        $this->assertStringContainsString('{purple}x{/purple}', $html);
    }

    public function test_color_shortcodes_cannot_inject_html(): void
    {
        // The marker survives sanitisation as text, but its contents are still
        // run through the purifier first, so no live element can escape.
        $html = Markdown::sanitizeWithColors('{red}<img src=x onerror=alert(1)>{/red}');
        $this->assertStringContainsString('class="t-color-red"', $html);
        // The payload survives only as escaped, inert text — no live element or
        // attribute. The literal word "onerror" inside &lt;…&gt; is harmless.
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_plain_sanitize_does_not_render_color_shortcodes(): void
    {
        // Colour shortcodes are a summary-only feature; report messages keep
        // the markers as literal text.
        $html = Markdown::sanitize('{green}hi{/green}');
        $this->assertStringNotContainsString('<span', $html);
        $this->assertStringContainsString('{green}hi{/green}', $html);
    }
}
