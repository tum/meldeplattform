<?php

namespace App\Http\Controllers;

use App\Models\File as FileModel;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController
{
    public function download(Request $request, string $name): StreamedResponse
    {
        $id = $request->string('id', '')->toString();
        if ($id === '') {
            abort(404);
        }

        $file = FileModel::where('uuid', $id)->first();
        if ($file === null) {
            abort(404);
        }

        // Deny any row whose disk was tampered with to point at a storage
        // backend other than the expected uploads volume.
        if ($file->disk !== 'uploads') {
            abort(404);
        }

        $this->authorizeFileAccess($request, $file);

        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->path)) {
            abort(404);
        }

        return $disk->download($file->path, $file->name);
    }

    /**
     * Verify the requester has access to the report this file belongs to.
     * Access is granted when:
     *  – a `token` query param matches the reporter_token or administrator_token
     *    of any report that contains the file via its message chain, OR
     *  – the authenticated user manages the topic of such a report.
     *
     * Orphaned files (not attached to any message/report) are always denied.
     */
    private function authorizeFileAccess(Request $request, FileModel $file): void
    {
        $reportIds = $file->messages()
            ->with('report:id')
            ->get()
            ->pluck('report.id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($reportIds === []) {
            abort(404);
        }

        $token = $request->string('token', '')->toString();
        if ($token !== '') {
            $valid = Report::whereIn('id', $reportIds)
                ->where(static function ($q) use ($token): void {
                    $q->where('reporter_token', $token)
                        ->orWhere('administrator_token', $token);
                })
                ->exists();

            if ($valid) {
                return;
            }
        }

        $user = $request->user();
        if ($user !== null) {
            $manageableTopicIds = Topic::query()->manageableBy($user)->pluck('id');
            $valid = Report::whereIn('id', $reportIds)
                ->whereIn('topic_id', $manageableTopicIds)
                ->exists();

            if ($valid) {
                return;
            }
        }

        abort(403);
    }
}
