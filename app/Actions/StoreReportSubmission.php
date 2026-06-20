<?php

namespace App\Actions;

use App\Http\Requests\SubmitReportRequest;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use App\Support\UploadSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreReportSubmission
{
    public function __construct(
        private readonly MessengerDispatcher $messengers,
        private readonly UploadSanitizer $sanitizer,
    ) {}

    /**
     * Compose the report body from the submitted field values, store any
     * uploads, persist a new Report + first Message in a single transaction,
     * then notify the topic's configured messengers about it.
     *
     * Body composition and upload storage happen inside the transaction so a
     * failure rolls back the File rows alongside the Report/Message — no
     * compensating cleanup of orphaned File records is required. Physical
     * files written to the `uploads` disk are removed on rollback too.
     */
    public function execute(SubmitReportRequest $request): Report
    {
        $topic = $request->topic();

        $authUser = $request->user();
        $creator = ($topic->require_login && $authUser !== null)
            ? ($authUser->email ?? $authUser->uid)
            : $request->emailOrNull();

        /** @var list<string> $storedPaths physical files written this run, for rollback */
        $storedPaths = [];

        try {
            $report = DB::transaction(function () use ($topic, $request, $creator, &$storedPaths): Report {
                // Create the report first so its reporter_token is available for
                // embedding into file download URLs inside the message body.
                $report = Report::create([
                    'topic_id' => $topic->id,
                    'creator' => $creator,
                ]);

                /** @var list<File> $files */
                $files = [];
                $messageBody = $this->composeBody($topic, $request, $files, $storedPaths, $report->reporter_token);

                $message = Message::create([
                    'report_id' => $report->id,
                    'content' => $messageBody,
                    'is_admin' => false,
                ]);
                if ($files !== []) {
                    $message->files()->sync(array_map(static fn (File $f): int => $f->id, $files));
                }
                $report->setRelation('messages', collect([$message]));

                return $report;
            });
        } catch (\Throwable $e) {
            // The DB rollback already discarded the File rows, but the bytes
            // were written to disk inside the transaction, so remove them.
            foreach ($storedPaths as $path) {
                Storage::disk('uploads')->delete($path);
            }
            throw $e;
        }

        $firstMessage = $report->messages->first();
        if ($firstMessage instanceof Message) {
            $this->messengers->dispatch(
                $topic,
                sprintf('[%s]: report #%d opened', $topic->name('en'), $report->id),
                $firstMessage,
                route('admin.report.show', ['topic' => $topic->id, 'report' => $report->id]),
            );
        }

        return $report;
    }

    /**
     * Build the markdown body by looping the topic's fields, storing any
     * uploads as they are encountered. Created File models are appended to
     * $files and their physical paths to $storedPaths (for rollback).
     * $reporterToken is embedded in each file download URL so the download
     * endpoint can verify the requester holds access to this report.
     *
     * @param list<File> $files
     * @param list<string> $storedPaths
     * @param-out list<string> $storedPaths
     */
    private function composeBody(Topic $topic, SubmitReportRequest $request, array &$files, array &$storedPaths, string $reporterToken): string
    {
        $messageBody = '';

        foreach ($topic->fields as $field) {
            $messageBody .= "\n**".$field->name('en')."**\n";

            if (! $field->type->isFileUpload()) {
                $messageBody .= $request->string((string) $field->id, '')->toString()."\n";

                continue;
            }

            $uploads = $request->file((string) $field->id);
            if ($uploads === null) {
                continue;
            }

            /** @var list<UploadedFile> $uploadList */
            $uploadList = array_values(is_array($uploads) ? $uploads : [$uploads]);

            foreach ($uploadList as $upload) {
                $file = $this->storeUpload($upload, $storedPaths);
                $files[] = $file;

                $messageBody .= '['.$file->name.']('
                    .route('file.download', ['name' => $file->name, 'id' => $file->uuid, 'token' => $reporterToken])
                    .')';
            }
        }

        return $messageBody;
    }

    /** @param list<string> $storedPaths */
    private function storeUpload(UploadedFile $upload, array &$storedPaths): File
    {
        // Prefer the server-detected extension over the client-supplied one;
        // fall back to the original extension when MIME-based detection fails
        // (validation has already restricted it to the allowlist).
        $ext = Str::of($upload->extension() ?: $upload->getClientOriginalExtension())
            ->lower()
            ->toString();

        $uuid = (string) Str::uuid();
        $storageName = $ext === '' ? $uuid : $uuid.'.'.$ext;
        $disk = 'uploads';
        $stored = $upload->storeAs('', $storageName, $disk);
        if (! is_string($stored)) {
            throw new \RuntimeException('Failed to store uploaded file.');
        }
        $path = $stored;

        // Register path for rollback BEFORE sanitization so a sanitization
        // failure does not leave a physical file without a cleanup reference.
        $storedPaths[] = $path;

        // Strip identifying metadata (e.g. image EXIF/GPS) from the stored file.
        $this->sanitizer->stripImageMetadata(Storage::disk($disk)->path($path), $ext);

        // Do NOT persist the client's original filename: it can itself
        // deanonymise the reporter (e.g. "max_mustermann_kündigung.pdf").
        return File::create([
            'uuid' => $uuid,
            'path' => $path,
            'disk' => $disk,
            'name' => $this->sanitizer->neutralFilename($uuid, $ext),
        ]);
    }
}
