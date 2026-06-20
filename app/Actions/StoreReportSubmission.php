<?php

namespace App\Actions;

use App\Http\Requests\SubmitReportRequest;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreReportSubmission
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

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
                /** @var list<File> $files */
                $files = [];
                $messageBody = $this->composeBody($topic, $request, $files, $storedPaths);

                $report = Report::create([
                    'topic_id' => $topic->id,
                    'creator' => $creator,
                ]);
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
                route('report.show', ['administratorToken' => $report->administrator_token]),
            );
        }

        return $report;
    }

    /**
     * Build the markdown body by looping the topic's fields, storing any
     * uploads as they are encountered. Created File models are appended to
     * $files and their physical paths to $storedPaths (for rollback).
     *
     * @param list<File> $files
     * @param list<string> $storedPaths
     */
    private function composeBody(Topic $topic, SubmitReportRequest $request, array &$files, array &$storedPaths): string
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
                $file = $this->storeUpload($upload);
                $files[] = $file;
                $storedPaths[] = $file->path;

                $messageBody .= '['.$file->name.']('
                    .route('file.download', ['name' => $file->name, 'id' => $file->uuid])
                    .')';
            }
        }

        return $messageBody;
    }

    private function storeUpload(UploadedFile $upload): File
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

        return File::create([
            'uuid' => $uuid,
            'path' => $path,
            'disk' => $disk,
            'name' => $safeName,
        ]);
    }
}
