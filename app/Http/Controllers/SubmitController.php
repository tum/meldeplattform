<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitReportRequest;
use App\Models\File as FileModel;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmitController
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

    public function store(SubmitReportRequest $request): RedirectResponse
    {
        $topic = $request->topic();
        $email = $request->emailOrNull();

        $message = '';
        /** @var list<FileModel> $storedFiles */
        $storedFiles = [];

        foreach ($topic->fields as $field) {
            $message .= "\n**".$field->name('en')."**\n";

            if (! in_array($field->type, ['file', 'files'], true)) {
                $message .= $request->string((string) $field->id, '')->toString()."\n";

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

                $message .= '['.$file->name.']('
                    .route('file.download', ['name' => $file->name, 'id' => $file->uuid])
                    .')';
            }
        }

        $report = DB::transaction(function () use ($topic, $message, $email, $storedFiles): Report {
            $report = Report::create([
                'topic_id' => $topic->id,
                'creator' => $email,
            ]);
            $msg = Message::create([
                'report_id' => $report->id,
                'content' => $message,
                'is_admin' => false,
            ]);
            if ($storedFiles !== []) {
                $msg->files()->sync(array_map(static fn (FileModel $f): int => $f->id, $storedFiles));
            }
            $report->setRelation('messages', collect([$msg]));

            return $report;
        });

        $adminUrl = route('report.show', ['administratorToken' => $report->administrator_token]);

        $firstMessage = $report->messages->first();
        if ($firstMessage instanceof Message) {
            $this->messengers->dispatch(
                $topic,
                sprintf('[%s]: report #%d opened', $topic->name('en'), $report->id),
                $firstMessage,
                $adminUrl,
            );
        }

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
