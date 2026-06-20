<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrackReportRequest;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReportAccessController
{
    public function create(): View
    {
        return view('pages.track');
    }

    public function store(TrackReportRequest $request): RedirectResponse
    {
        $code = $request->string('code')->toString();

        $report = Report::findByReceiptCode($code);

        if ($report === null) {
            // Uniform error: never disclose whether the code was malformed or
            // simply unknown, so the form can't be used as an oracle.
            return redirect()->back()->withErrors([
                'code' => __('track_not_found'),
            ]);
        }

        return redirect()->route('report.show', ['reporterToken' => $report->reporter_token]);
    }
}
