<?php

namespace App\Http\Controllers;

use App\Actions\StoreReportSubmission;
use App\Http\Requests\SubmitReportRequest;
use App\Models\File as FileModel;
use App\Support\UploadSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmitController
{
    public function __construct(
        private readonly StoreReportSubmission $action,
        private readonly UploadSanitizer $sanitizer,
    ) {}

    public function store(SubmitReportRequest $request): RedirectResponse
    {
        $topic = $request->topic();

        if ($topic->require_login && ! Auth::check()) {
            abort(403);
        }

        $messageBody = '';
        /** @var list<FileModel> $storedFiles */
        $storedFiles = [];

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
                $file = $this->storeUpload($upload);
                $storedFiles[] = $file;

                $messageBody .= '['.$file->name.']('
                    .route('file.download', ['name' => $file->name, 'id' => $file->uuid])
                    .')';
            }
        }

        $authUser = Auth::user();
        $creator = ($topic->require_login && $authUser !== null)
            ? ($authUser->email ?? $authUser->uid)
            : $request->emailOrNull();

        try {
            $report = $this->action->execute($topic, $messageBody, $creator, $storedFiles);
        } catch (\Throwable $e) {
            // Roll back orphaned files: the DB transaction in execute() already
            // reverted the Report/Message rows, but the File records and physical
            // files were created before the transaction started, so clean them up.
            foreach ($storedFiles as $file) {
                Storage::disk($file->disk)->delete($file->path);
                $file->delete();
            }
            throw $e;
        }

        // Issue a one-time receipt code so an anonymous reporter can return to
        // this report later without the URL — flashed to the session and shown
        // exactly once on the confirmation page.
        $receiptCode = $report->issueReceiptCode();

        return redirect()
            ->route('report.show', ['reporterToken' => $report->reporter_token])
            ->with('receipt_code', $receiptCode);
    }

    private function storeUpload(UploadedFile $upload): FileModel
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

        // Strip identifying metadata (e.g. image EXIF/GPS) from the stored file.
        $this->sanitizer->stripImageMetadata(Storage::disk($disk)->path($path), $ext);

        // Do NOT persist the client's original filename: it can itself
        // deanonymise the reporter (e.g. "max_mustermann_kündigung.pdf").
        return FileModel::create([
            'uuid' => $uuid,
            'path' => $path,
            'disk' => $disk,
            'name' => $this->sanitizer->neutralFilename($uuid, $ext),
        ]);
    }
}
