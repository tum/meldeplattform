<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Turns the bare download links a report body carries into attachment cards.
 *
 * Uploads are recorded in the message body as ordinary markdown links, so the
 * sanitized HTML renders them as a run of plain anchors with nothing between
 * them — several attachments collide into one unreadable line. This decorator
 * runs *after* the purifier (same trick as Markdown's colour shortcodes: the
 * markup we emit is our own, never author input) and swaps every anchor that
 * points at one of the message's own files for a card carrying a file-type
 * icon, the name and a size hint, then groups adjacent cards into a grid so
 * they lay out with real spacing.
 *
 * Anchors are matched on the file UUID in the query string rather than on the
 * URL host, so bodies written before a domain change still resolve and a link
 * a reporter typed by hand can never pose as an attachment.
 */
class AttachmentLinks
{
    /**
     * Extension → card kind. The kind is the only value interpolated into the
     * emitted class attribute, so the attribute set stays closed regardless of
     * what a file is called. Anything unlisted renders as the generic kind.
     */
    private const KINDS = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image',
        'gif' => 'image', 'webp' => 'image',
        'pdf' => 'pdf',
        'doc' => 'doc', 'docx' => 'doc', 'odt' => 'doc',
        'rtf' => 'doc', 'txt' => 'doc',
        'xls' => 'sheet', 'xlsx' => 'sheet', 'ods' => 'sheet', 'csv' => 'sheet',
        'zip' => 'archive', 'tar' => 'archive', 'gz' => 'archive', '7z' => 'archive',
        'mp3' => 'audio', 'wav' => 'audio', 'm4a' => 'audio', 'ogg' => 'audio',
        'mp4' => 'video', 'webm' => 'video',
    ];

    private const ICON_ATTRS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        .'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';

    /** Sheet of paper with a folded corner – the base for the text-ish kinds. */
    private const ICON_PAGE = '<path d="M13.5 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5L13.5 3Z"/>'
        .'<path d="M13.5 3v5.5H19"/>';

    /** @var array<string, string> kind → icon body */
    private const ICONS = [
        'image' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/>'
            .'<path d="m4 17.5 4.5-4.5 3 3L15 12l5.5 5.5"/>',
        'pdf' => self::ICON_PAGE.'<path d="M8.5 13.5h3M8.5 16.5h7"/>',
        'doc' => self::ICON_PAGE.'<path d="M8.5 13h7M8.5 16.5h4.5"/>',
        'sheet' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/>'
            .'<path d="M3.5 9.5h17M3.5 14.5h17M9.5 4.5v15"/>',
        'archive' => '<path d="m3.5 7.5 8.5-4.5 8.5 4.5v9L12 21l-8.5-4.5Z"/><path d="M12 12v9M3.5 7.5 12 12l8.5-4.5"/>',
        'audio' => '<path d="M9 17.5V5.5l10-2v12"/><circle cx="6.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="15.5" r="2.5"/>',
        'video' => '<rect x="3" y="5.5" width="13" height="13" rx="2"/><path d="m16 10.5 5-3v9l-5-3Z"/>',
        'file' => self::ICON_PAGE,
    ];

    private const ICON_DOWNLOAD = '<path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14"/>';

    /**
     * Decorate every attachment anchor in $html that belongs to $files.
     *
     * @param Collection<int, File> $files the files of the message $html renders
     */
    public static function decorate(string $html, Collection $files): string
    {
        if ($files->isEmpty() || ! str_contains($html, '<a href=')) {
            return $html;
        }

        /** @var array<string, File> $byUuid */
        $byUuid = [];
        foreach ($files as $file) {
            if ($file->uuid !== '') {
                $byUuid[$file->uuid] = $file;
            }
        }

        $decorated = preg_replace_callback(
            '#<a href="([^"]+)">[^<]*</a>#',
            static function (array $m) use ($byUuid): string {
                $file = self::matchFile($m[1], $byUuid);

                return $file === null ? $m[0] : self::card($m[1], $file);
            },
            $html,
        );

        return self::group($decorated ?? $html);
    }

    /**
     * The file this href downloads, or null when the anchor is an ordinary
     * link the reporter wrote.
     *
     * @param array<string, File> $byUuid
     */
    private static function matchFile(string $href, array $byUuid): ?File
    {
        if (! str_contains($href, '/file/')) {
            return null;
        }

        foreach ($byUuid as $uuid => $file) {
            if (str_contains($href, 'id='.$uuid)) {
                return $file;
            }
        }

        return null;
    }

    /** Render one attachment card. $href is already-purified, escaped markup. */
    private static function card(string $href, File $file): string
    {
        $ext = mb_strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        $kind = self::KINDS[$ext] ?? 'file';

        $meta = array_filter([
            $ext === '' ? null : mb_strtoupper($ext),
            self::humanSize($file),
        ]);

        return '<a class="attachment attachment-'.$kind.'" href="'.$href.'" download'
            .' title="'.self::esc($file->name).'">'
            .'<span class="attachment-icon" aria-hidden="true"><svg '.self::ICON_ATTRS.'>'
            .self::ICONS[$kind].'</svg></span>'
            .'<span class="attachment-text">'
            .'<span class="attachment-name">'.self::esc($file->name).'</span>'
            .'<span class="attachment-meta">'.self::esc(implode(' · ', $meta)).'</span>'
            .'</span>'
            .'<span class="attachment-download" aria-hidden="true"><svg '.self::ICON_ATTRS.'>'
            .self::ICON_DOWNLOAD.'</svg></span>'
            .'</a>';
    }

    /**
     * Wrap each run of adjacent cards in a grid container. Runs are what the
     * body produces — one field's uploads follow each other with only
     * whitespace between them — so this is what puts several attachments into
     * a wrapping row instead of a single crowded line.
     */
    private static function group(string $html): string
    {
        $grouped = preg_replace(
            '#(?:<a class="attachment [^>]*>.*?</a>\s*)+#s',
            '<span class="attachment-grid">$0</span>',
            $html,
        );

        return $grouped ?? $html;
    }

    /**
     * Human-readable size of the stored blob, or null when it cannot be read —
     * a card without a size is still useful, a render that throws is not.
     */
    private static function humanSize(File $file): ?string
    {
        try {
            $disk = Storage::disk($file->disk);
            if (! $disk->exists($file->path)) {
                return null;
            }
            $bytes = $disk->size($file->path);
        } catch (\Throwable) {
            return null;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
