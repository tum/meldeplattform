<?php

namespace App\Http\Controllers;

use App\Models\File as FileModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    public function download(Request $request, string $name): BinaryFileResponse
    {
        $id = $request->string('id', '')->toString();
        if ($id === '') {
            abort(404);
        }

        $file = FileModel::where('uuid', $id)->first();
        if ($file === null) {
            abort(404);
        }

        $absLocation = realpath($file->location);
        $rawDir = Storage::disk('uploads')->path('');
        $absFileDir = realpath($rawDir);

        if ($absLocation === false || $absFileDir === false || ! str_starts_with($absLocation, $absFileDir)) {
            abort(403);
        }

        return response()->download($absLocation, $file->name);
    }
}
