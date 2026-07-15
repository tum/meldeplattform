<?php

namespace App\Support;

use App\Exceptions\CannotStripImageMetadata;
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

    /** Raster types we re-encode. Anything else is out of scope by design. */
    private const STRIPPABLE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Re-encode a raster image in place to drop all metadata.
     *
     * Fails closed: if a strippable raster cannot be re-encoded, this throws
     * rather than leaving the original bytes in place. The UI promises reporters
     * that image metadata is removed, so silently storing an unstripped image
     * hands an administrator GPS coordinates the reporter believed were gone —
     * on a whistleblowing platform that is worse than refusing the upload.
     *
     * No-op for types we never strip (PDF/Office/archives/media); reporters are
     * warned in the UI to scrub those themselves.
     *
     * @throws CannotStripImageMetadata when a raster upload cannot be re-encoded
     */
    public function stripImageMetadata(string $absolutePath, string $extension): void
    {
        $ext = strtolower($extension);

        if (! in_array($ext, self::STRIPPABLE, true) || ! is_file($absolutePath)) {
            return;
        }

        if (! extension_loaded('gd')) {
            // A deployment fault, not reporter input: ext-gd is a hard composer
            // requirement precisely so this is unreachable. Refuse loudly rather
            // than pass every image through unstripped, which is what this
            // silently did before — and production is LRZ shared hosting, not
            // the Dockerfile that installs GD.
            Log::critical('UploadSanitizer: ext-gd is missing, refusing to store an unstripped image');

            throw new \RuntimeException('ext-gd is unavailable, cannot strip image metadata.');
        }

        // Exhaustive by construction: the guard above restricts $ext to
        // STRIPPABLE, so adding a type there without a decoder here is a
        // static-analysis error rather than a silently unstripped upload.
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($absolutePath),
            'png' => @imagecreatefrompng($absolutePath),
            'gif' => @imagecreatefromgif($absolutePath),
            'webp' => @imagecreatefromwebp($absolutePath),
        };

        if ($image === false) {
            Log::warning('UploadSanitizer: could not decode image for metadata stripping', [
                'extension' => $ext,
            ]);

            throw new CannotStripImageMetadata('Could not decode '.$ext.' image for metadata stripping.');
        }

        // Preserve transparency for formats that support it.
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $this->reencode($image, $absolutePath, $ext);
        imagedestroy($image);
    }

    /** @throws CannotStripImageMetadata when the re-encode fails */
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
            // The file on disk is now the original or a partial write; either
            // way its metadata is not gone. Fail closed — the caller rolls the
            // upload back.
            Log::warning('UploadSanitizer: failed to re-encode image for metadata stripping', [
                'extension' => $ext,
            ]);

            throw new CannotStripImageMetadata('Failed to re-encode '.$ext.' image for metadata stripping.');
        }
    }
}
