<?php

namespace Tests\Feature;

use App\Actions\StoreReportSubmission;
use App\Http\Requests\SubmitReportRequest;
use App\Models\Field;
use App\Models\File;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Refusing an unstrippable image must reach the reporter as a field error they
 * can act on — not a 500. Fixing one bad failure mode by introducing another
 * would be no fix at all.
 *
 * The request is built directly rather than through $this->post(): Laravel's
 * test client merges extracted uploads with array_merge(), which renumbers
 * integer keys, and file inputs here are named after the numeric field ID — so
 * a file posted as `14` arrives as `0` and the field reads as absent. That is a
 * harness artifact (real multipart requests populate $_FILES directly), but it
 * makes HTTP-level upload assertions vacuous.
 */
class UploadFailClosedSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function fileFieldTopic(): Field
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);

        return Field::create([
            'topic_id' => $topic->id,
            'name_de' => 'Beleg', 'name_en' => 'Evidence',
            'type' => 'file',
            'required' => false,
            'position' => 0,
        ]);
    }

    private function requestWith(Field $field, UploadedFile $upload): SubmitReportRequest
    {
        $request = SubmitReportRequest::create(
            '/submit',
            'POST',
            ['topic' => $field->topic_id],
            [],
            [(string) $field->id => $upload],
        );
        $request->setContainer($this->app);
        $request->setUserResolver(static fn () => null);

        return $request;
    }

    /**
     * A truncated JPEG — the realistic shape of this failure. finfo reads the
     * header and reports image/jpeg, so it passes the `mimes:`/`extensions:`
     * rules and reaches the sanitiser as a jpg; GD then cannot decode it. Its
     * EXIF/APP1 segment sits right after the SOI marker, so the metadata is
     * very much still there — which is exactly why storing it unstripped leaks.
     */
    private function truncatedJpeg(): UploadedFile
    {
        $gd = imagecreatetruecolor(64, 64);
        $full = sys_get_temp_dir().'/upload-full-'.uniqid().'.jpg';
        imagejpeg($gd, $full);
        imagedestroy($gd);

        $bytes = (string) file_get_contents($full);
        @unlink($full);

        $path = sys_get_temp_dir().'/upload-'.uniqid().'-photo.jpg';
        file_put_contents($path, substr($bytes, 0, (int) (strlen($bytes) * 0.4)));

        // $test = true so isValid() passes without a real HTTP upload.
        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_an_unstrippable_image_becomes_a_field_error_not_a_500(): void
    {
        Storage::fake('uploads');
        $field = $this->fileFieldTopic();
        $upload = $this->truncatedJpeg();

        try {
            app(StoreReportSubmission::class)->execute($this->requestWith($field, $upload));
            $this->fail('the unstrippable image was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey((string) $field->id, $e->errors());
            $this->assertSame(__('upload_image_unreadable'), $e->errors()[(string) $field->id][0]);
        }
    }

    public function test_a_refused_upload_leaves_no_report_file_row_or_blob_behind(): void
    {
        Storage::fake('uploads');
        $field = $this->fileFieldTopic();
        $upload = $this->truncatedJpeg();

        try {
            app(StoreReportSubmission::class)->execute($this->requestWith($field, $upload));
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Report::count(), 'a half-built report survived the rollback');
        $this->assertSame(0, File::count(), 'a File row survived the rollback');
        $this->assertEmpty(Storage::disk('uploads')->allFiles(), 'the unstripped blob was left on disk');
    }

    public function test_a_strippable_image_is_accepted_and_stored(): void
    {
        // Control: fail-closed must not reject legitimate uploads.
        Storage::fake('uploads');
        $field = $this->fileFieldTopic();

        $gd = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/upload-'.uniqid().'-real.jpg';
        imagejpeg($gd, $path);
        imagedestroy($gd);
        $upload = new UploadedFile($path, 'real.jpg', 'image/jpeg', null, true);

        app(StoreReportSubmission::class)->execute($this->requestWith($field, $upload));

        $this->assertSame(1, Report::count(), 'the report was not created');
        $this->assertSame(1, File::count(), 'the valid image was not stored');
        $this->assertCount(1, Storage::disk('uploads')->allFiles());
    }
}
