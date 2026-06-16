<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\View\View;

class FormController
{
    public function show(Topic $topic): View|RedirectResponse
    {
        if ($topic->require_login && ! Auth::check()) {
            session(['url.intended' => url()->current()]);

            return redirect()->route('saml.login');
        }

        $topic->load(['fields', 'admins']);

        return view('pages.form', [
            'topic' => $topic,
            'maxUploadMb' => Config::integer('meldeplattform.max_upload_mb', 10),
        ]);
    }
}
