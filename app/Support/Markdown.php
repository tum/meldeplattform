<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Mews\Purifier\Facades\Purifier;

class Markdown
{
    /**
     * Render user-supplied markdown to safe HTML.
     *
     * Matches the Go version's behaviour: HTML-escape first, then parse
     * markdown, then sanitize with a restrictive HTML purifier.
     */
    public static function sanitize(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $html = (string) $converter->convert($escaped);

        /** @var string $clean */
        $clean = Purifier::clean($html, 'meldeplattform');

        return $clean;
    }

    /**
     * Render static operator content (imprint / privacy) – same pipeline,
     * but allow a broader tag set since the author is trusted.
     */
    public static function renderOperatorContent(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        /** @var string $clean */
        $clean = Purifier::clean((string) $converter->convert($markdown), 'operator');

        return $clean;
    }
}
