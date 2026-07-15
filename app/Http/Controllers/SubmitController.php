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

        // Defense in depth: the public form is already gone for a deactivated
        // topic (FormController), but reject a hand-crafted submission too so a
        // topic taken offline can never receive a new report.
        abort_if($topic->isDeactivated(), 404);

        if ($topic->require_login && ! Auth::check()) {
            abort(403);
        }

        $report = $this->action->execute($request);

        // Issue a receipt code so an anonymous reporter can return to this
        // report later without the URL. It is *displayed* once — flashed to the
        // session and shown only on the confirmation page, never persisted in
        // plaintext — but it stays valid for repeated use, which is the point:
        // it is the reporter's durable way back in.
        $receiptCode = $report->issueReceiptCode();

        return redirect()
            ->route('report.show', ['reporterToken' => $report->reporter_token])
            ->with('receipt_code', $receiptCode);
    }
}
