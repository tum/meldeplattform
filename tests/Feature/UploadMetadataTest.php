<?php

namespace Tests\Feature;

use App\Support\UploadSanitizer;
use Tests\TestCase;

class UploadMetadataTest extends TestCase
{
    public function test_image_exif_metadata_is_stripped(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD not available.');
        }

        // Build a tiny valid JPEG, then splice in an APP1/EXIF segment carrying
        // a recognisable marker (stands in for GPS/camera data).
        $img = imagecreatetruecolor(4, 4);
        $this->assertNotFalse($img);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        $payload = "Exif\x00\x00GPS-SECRET-MARKER";
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $withExif = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

        $this->assertStringContainsString('GPS-SECRET-MARKER', $withExif);

        $path = (string) tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, $withExif);

        (new UploadSanitizer)->stripImageMetadata($path, 'jpg');

        $cleaned = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringNotContainsString('GPS-SECRET-MARKER', $cleaned);
        // Still a usable image after stripping.
        $this->assertNotFalse(imagecreatefromstring($cleaned));
    }

    public function test_neutral_filename_never_contains_the_original_name(): void
    {
        // The stored display name is derived only from the file's UUID +
        // extension, so an identifying client filename cannot leak through.
        $sanitizer = new UploadSanitizer;
        $uuid = '0123abcd-89ef-4012-8345-6789abcdef01';

        $this->assertSame('attachment-0123abcd.pdf', $sanitizer->neutralFilename($uuid, 'pdf'));
        // No extension → no trailing dot.
        $this->assertSame('attachment-0123abcd', $sanitizer->neutralFilename($uuid, ''));
    }
}
