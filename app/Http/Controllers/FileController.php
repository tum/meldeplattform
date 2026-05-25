<?php

namespace App\Http\Controllers;

use App\Models\File as FileModel;
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

        $disk = Storage::disk($file->disk);
        if (! $disk->exists($file->path)) {
            abort(404);
        }

        return $disk->download($file->path, $file->name);
    }
}
