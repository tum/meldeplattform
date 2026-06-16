<?php

namespace App\Http\Controllers;

use App\Actions\StoreReportSubmission;
use App\Http\Requests\SubmitReportRequest;
use App\Models\File as FileModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SubmitController
{
    public function __construct(private readonly StoreReportSubmission $action) {}

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

        $report = $this->action->execute($topic, $messageBody, $creator, $storedFiles);

        return redirect()->route('report.show', ['reporterToken' => $report->reporter_token]);
    }

    private function storeUpload(UploadedFile $upload): FileModel
    {
        // Prefer the server-detected extension over the client-supplied one;
        // fall back to the original extension when MIME-based detection fails
        // (validation has already restricted it to the allowlist).
        $ext = Str::of($upload->extension() ?: $upload->getClientOriginalExtension())
            ->lower()
            ->toString();

        $safeName = basename($upload->getClientOriginalName());
        if ($safeName === '' || $safeName === '.') {
            $safeName = (string) Str::uuid();
        }

        $uuid = (string) Str::uuid();
        $storageName = $ext === '' ? $uuid : $uuid.'.'.$ext;
        $disk = 'uploads';
        $path = $upload->storeAs('', $storageName, $disk);

        return FileModel::create([
            'uuid' => $uuid,
            'path' => $path,
            'disk' => $disk,
            'name' => $safeName,
        ]);
    }
}
