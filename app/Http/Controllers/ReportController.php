<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplyRequest;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

    public function show(Request $request): View
    {
        $report = $this->resolveReporterReport($request);
        $report->load('messages.files', 'topic');

        return view('pages.report', [
            'report' => $report,
            'isAdministrator' => false,
        ]);
    }

    public function reply(ReplyRequest $request): RedirectResponse
    {
        $report = $this->resolveReporterReport($request);

        if (! $report->state->allowsReply()) {
            abort(403);
        }

        $reply = $request->string('reply')->toString();
        $topic = $report->topic;

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $reply,
            'is_admin' => false,
        ]);

        $this->messengers->dispatch(
            $topic,
            sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id),
            $message,
            route('admin.report.show', ['topic' => $topic->id, 'report' => $report->id]),
        );

        return redirect()->route('report.show', [
            'reporterToken' => $request->string('reporterToken', '')->toString(),
        ]);
    }

    private function resolveReporterReport(Request $request): Report
    {
        $reporterToken = $request->string('reporterToken', '')->toString();

        if ($reporterToken === '') {
            abort(404);
        }

        $report = Report::where('reporter_token', $reporterToken)->first();
        if ($report === null) {
            abort(404);
        }

        return $report;
    }
}
