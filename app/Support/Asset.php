<?php

namespace App\Support;

/**
 * Cache-busting URLs for the hand-written assets under public/.
 *
 * There is no build step, so nothing ever renames css/app.css or js/app.js.
 * Neither nginx (Docker) nor the LRZ Apache sends Cache-Control for them, so
 * browsers fall back to heuristic freshness — roughly a tenth of the time
 * since Last-Modified — and a phone that fetched app.js months ago keeps
 * using it for weeks without revalidating. That is how the small-screen
 * menu toggle shipped dead: new markup, stale script. Appending the file's
 * mtime changes the URL whenever the content does.
 */
final class Asset
{
    public static function url(string $path): string
    {
        $file = public_path($path);
        $mtime = is_file($file) ? filemtime($file) : false;

        return $mtime === false ? asset($path) : asset($path).'?v='.$mtime;
    }
}
