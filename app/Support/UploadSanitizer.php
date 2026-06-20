<?php

namespace App\Support;

use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Strips identifying metadata from uploaded files before they are handed to
 * administrators. On a whistleblowing platform an image's EXIF block (GPS
 * coordinates, camera serial, capture time) or a document's embedded author
 * can deanonymise the reporter.
 *
 * Raster images are re-encoded with GD, which does not carry EXIF/XMP/IPTC
 * across, so the re-encoded file is metadata-free. Non-raster formats (PDF,
 * Office documents, archives, media) are left untouched — reporters are warned
 * in the UI to scrub those themselves.
 */
class UploadSanitizer
{
    /**
     * Build a neutral display name for a stored upload, derived only from the
     * file's own UUID + extension. The client's original filename is never
     * used, since it can itself identify the reporter (e.g.
     * "max_mustermann_kuendigung.pdf"). By construction the original name
     * cannot leak into the result.
     */
    public function neutralFilename(string $uuid, string $extension): string
    {
        $ext = strtolower($extension);

        return 'attachment-'.substr($uuid, 0, 8).($ext === '' ? '' : '.'.$ext);
    }

    /**
     * Re-encode a raster image in place to drop all metadata. No-op for
     * unsupported types or when GD is unavailable.
     */
    public function stripImageMetadata(string $absolutePath, string $extension): void
    {
        if (! extension_loaded('gd') || ! is_file($absolutePath)) {
            return;
        }

        $ext = strtolower($extension);

        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($absolutePath),
            'png' => @imagecreatefrompng($absolutePath),
            'gif' => @imagecreatefromgif($absolutePath),
            'webp' => @imagecreatefromwebp($absolutePath),
            default => null, // not a raster type we strip — leave as-is
        };

        if ($image === null) {
            return;
        }

        if ($image === false) {
            // Corrupt or undecodable image: leave the original bytes in place
            // rather than risk destroying the upload.
            Log::warning('UploadSanitizer: could not decode image for metadata stripping', [
                'extension' => $ext,
            ]);

            return;
        }

        // Preserve transparency for formats that support it.
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $this->reencode($image, $absolutePath, $ext);
        imagedestroy($image);
    }

    private function reencode(GdImage $image, string $absolutePath, string $ext): void
    {
        $ok = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $absolutePath, 90),
            'png' => imagepng($image, $absolutePath),
            'gif' => imagegif($image, $absolutePath),
            'webp' => imagewebp($image, $absolutePath),
            default => true,
        };

        if ($ok === false) {
            Log::warning('UploadSanitizer: failed to re-encode image for metadata stripping', [
                'extension' => $ext,
            ]);
        }
    }
}
