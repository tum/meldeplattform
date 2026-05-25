<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\View\View;

class FormController
{
    public function show(Topic $topic): View
    {
        $topic->load(['fields', 'admins']);

        return view('pages.form', [
            'topic' => $topic,
        ]);
    }
}
