<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;

class FormController
{
    public function show(Topic $topic): View
    {
        $topic->load(['fields', 'admins']);

        return view('pages.form', [
            'topic' => $topic,
            'maxUploadMb' => Config::integer('meldeplattform.max_upload_mb', 10),
        ]);
    }
}
