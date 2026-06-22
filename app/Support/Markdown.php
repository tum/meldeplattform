<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Mews\Purifier\Facades\Purifier;

class Markdown
{
    /**
     * Brand colour shortcodes recognised in operator-authored summaries,
     * mapped to the fixed CSS palette class they render to. The names are the
     * only values ever interpolated into the emitted markup, so the attribute
     * set stays closed regardless of author input.
     */
    private const COLOR_CLASSES = [
        'red' => 't-color-red',
        'green' => 't-color-green',
        'yellow' => 't-color-yellow',
        'blue' => 't-color-blue',
        'grey' => 't-color-grey',
    ];

    /**
     * Render user-supplied markdown to safe HTML.
     *
     * Matches the Go version's behaviour: HTML-escape first, then parse
     * markdown, then sanitize with a restrictive HTML purifier.
     */
    public static function sanitize(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) self::converter()->convert($escaped);

        /** @var string $clean */
        $clean = Purifier::clean($html, 'meldeplattform');

        return $clean;
    }

    /**
     * Like sanitize(), but additionally renders the brand colour shortcodes
     * — {green}…{/green}, plus red/yellow/blue/grey — into spans carrying a
     * fixed palette class. Used for operator-authored topic summaries.
     *
     * The conversion runs *after* the purifier so authors cannot smuggle HTML
     * through it: the shortcode markers survive sanitisation as plain text,
     * and we wrap already-sanitised content in a span whose class comes from
     * the closed COLOR_CLASSES allowlist — never from author input.
     */
    public static function sanitizeWithColors(string $markdown): string
    {
        return self::applyColorShortcodes(self::sanitize($markdown));
    }

    /**
     * Replace balanced {name}…{/name} shortcodes (name ∈ COLOR_CLASSES) with
     * palette spans. Unknown names and unbalanced markers are left untouched
     * as literal text so authors notice their mistake.
     */
    private static function applyColorShortcodes(string $html): string
    {
        $names = implode('|', array_keys(self::COLOR_CLASSES));

        return (string) preg_replace_callback(
            '#\{('.$names.')\}(.*?)\{/\1\}#s',
            static fn (array $m): string => '<span class="'.self::COLOR_CLASSES[$m[1]].'">'.$m[2].'</span>',
            $html,
        );
    }

    /**
     * Render static operator content (imprint / privacy) – same pipeline,
     * but allow a broader tag set since the author is trusted.
     */
    public static function renderOperatorContent(string $markdown): string
    {
        /** @var string $clean */
        $clean = Purifier::clean((string) self::converter()->convert($markdown), 'operator');

        return $clean;
    }

    private static function converter(): GithubFlavoredMarkdownConverter
    {
        return new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
