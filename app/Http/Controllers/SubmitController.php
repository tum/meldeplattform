<?php

namespace App\Http\Controllers;

use App\Actions\StoreReportSubmission;
use App\Http\Requests\SubmitReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class SubmitController
{
    public function __construct(private readonly StoreReportSubmission $action) {}

    public function store(SubmitReportRequest $request): RedirectResponse
    {
        $topic = $request->topic();

        if ($topic->require_login && ! Auth::check()) {
            abort(403);
        }

        $report = $this->action->execute($request);

        // Issue a one-time receipt code so an anonymous reporter can return to
        // this report later without the URL — flashed to the session and shown
        // exactly once on the confirmation page.
        $receiptCode = $report->issueReceiptCode();

        return redirect()
            ->route('report.show', ['reporterToken' => $report->reporter_token])
            ->with('receipt_code', $receiptCode);
    }
}
