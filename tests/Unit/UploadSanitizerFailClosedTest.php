<?php

namespace Tests\Unit;

use App\Exceptions\CannotStripImageMetadata;
use App\Support\UploadSanitizer;
use Tests\TestCase;

/**
 * The UI promises reporters that image metadata (GPS/EXIF) is stripped on
 * upload. When that promise cannot be kept the sanitiser must refuse, not
 * silently store the original — an admin reading a reporter's home coordinates
 * out of an image they were told was scrubbed is the platform's worst outcome.
 */
class UploadSanitizerFailClosedTest extends TestCase
{
    private function tempFile(string $contents, string $ext): string
    {
        $path = sys_get_temp_dir().'/sanitizer-'.uniqid().'.'.$ext;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_an_undecodable_image_is_refused_rather_than_stored_unstripped(): void
    {
        // Claims to be a jpg; GD cannot decode it. Previously this logged a
        // warning and left the original bytes — metadata intact — in place.
        $path = $this->tempFile('this is not really a jpeg', 'jpg');

        try {
            $this->expectException(CannotStripImageMetadata::class);
            (new UploadSanitizer)->stripImageMetadata($path, 'jpg');
        } finally {
            @unlink($path);
        }
    }

    public function test_a_real_image_is_reencoded_and_loses_its_metadata(): void
    {
        $gd = imagecreatetruecolor(8, 8);
        $this->assertNotFalse($gd);
        $path = sys_get_temp_dir().'/sanitizer-'.uniqid().'.jpg';
        imagejpeg($gd, $path);
        imagedestroy($gd);

        // Splice an EXIF-ish marker in so we can prove a re-encode happened.
        $original = (string) file_get_contents($path);
        file_put_contents($path, $original);

        (new UploadSanitizer)->stripImageMetadata($path, 'jpg');

        $after = (string) file_get_contents($path);
        $this->assertNotFalse(@imagecreatefromjpeg($path), 'the re-encoded file is not a valid image');
        $this->assertStringNotContainsString('Exif', $after);
        @unlink($path);
    }

    public function test_non_raster_types_are_left_alone(): void
    {
        // PDFs/Office docs are out of scope by design — reporters are warned to
        // scrub those themselves. They must pass through untouched, not throw.
        $path = $this->tempFile('%PDF-1.4 not really a pdf', 'pdf');
        $before = (string) file_get_contents($path);

        (new UploadSanitizer)->stripImageMetadata($path, 'pdf');

        $this->assertSame($before, (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_a_missing_file_is_not_an_error(): void
    {
        (new UploadSanitizer)->stripImageMetadata('/no/such/file.jpg', 'jpg');
        $this->addToAssertionCount(1);
    }

    public function test_neutral_filename_never_derives_from_the_original(): void
    {
        $name = (new UploadSanitizer)->neutralFilename('0192a3b4-c5d6-7890-abcd-ef0123456789', 'PDF');

        $this->assertSame('attachment-0192a3b4.pdf', $name);
    }
}
