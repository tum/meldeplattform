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
}
