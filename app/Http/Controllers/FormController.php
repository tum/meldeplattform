<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\View\View;

class FormController extends Controller
{
    public function show(int $topicID): View
    {
        /** @var Topic $topic */
        $topic = Topic::with(['fields', 'admins'])->findOrFail($topicID);

        return view('pages.form', [
            'topic' => $topic,
        ]);
    }
}
