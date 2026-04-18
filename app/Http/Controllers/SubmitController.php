<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitReportRequest;
use App\Models\File as FileModel;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmitController extends Controller
{
    public function store(SubmitReportRequest $request): RedirectResponse
    {
        $topic = $request->topic();
        $email = $request->emailOrNull();

        /** @var list<string> $extensions */
        $extensions = array_values(array_filter(
            Config::array('meldeplattform.allowed_extensions', []),
            'is_string',
        ));
        $maxBytes = Config::integer('meldeplattform.max_upload_mb', 10) * 1024 * 1024;

        $message = '';
        /** @var list<FileModel> $storedFiles */
        $storedFiles = [];

        foreach ($topic->fields as $field) {
            $message .= "\n**".$field->name('en')."**\n";

            if (! in_array($field->type, ['file', 'files'], true)) {
                $value = $request->string((string) $field->id, '')->toString();
                if ($value === '' && $field->required) {
                    abort(400, 'required field not provided');
                }
                $message .= $value."\n";

                continue;
            }

            $uploads = $request->file((string) $field->id);
            if ($uploads === null && $field->required) {
                abort(400, 'required file field not provided');
            }
            if ($uploads === null) {
                continue;
            }

            /** @var list<UploadedFile> $uploadList */
            $uploadList = array_values(is_array($uploads) ? $uploads : [$uploads]);

            foreach ($uploadList as $upload) {
                if (! $upload->isValid()) {
                    continue;
                }
                if ($upload->getSize() > $maxBytes) {
                    abort(400, 'file too large');
                }
                $ext = Str::of($upload->getClientOriginalExtension())->lower()->toString();
                if (! in_array($ext, $extensions, true)) {
                    abort(400, 'file type not allowed');
                }

                $safeName = basename($upload->getClientOriginalName());
                if ($safeName === '' || $safeName === '.') {
                    $safeName = (string) Str::uuid();
                }

                $uuid = (string) Str::uuid();
                $storageName = $uuid.'.'.$ext;
                Storage::disk('uploads')->putFileAs('', $upload, $storageName);
                $absPath = Storage::disk('uploads')->path($storageName);

                $file = FileModel::create([
                    'uuid' => $uuid,
                    'location' => $absPath,
                    'name' => $safeName,
                ]);
                $storedFiles[] = $file;

                $message .= '['.$file->name.']('
                    .rtrim(Config::string('app.url'), '/')
                    .'/file/'.rawurlencode($file->name).'?id='.$file->uuid.')';
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

        $reportUrl = rtrim(Config::string('app.url'), '/')
            .'/report?administratorToken='.$report->administrator_token;

        $firstMessage = $report->messages->first();
        if ($firstMessage instanceof Message) {
            MessengerDispatcher::dispatch(
                $topic,
                sprintf('[%s]: report #%d opened', $topic->name('en'), $report->id),
                $firstMessage,
                $reportUrl,
            );
        }

        return redirect('/report?reporterToken='.$report->reporter_token);
    }
}
