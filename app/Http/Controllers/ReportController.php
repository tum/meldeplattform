<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplyRequest;
use App\Mail\ReportNotification;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ReportController
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

    public function show(Request $request): View
    {
        [$report, $isAdmin] = $this->resolveReport($request);

        $report->load('messages.files', 'topic');

        return view('pages.report', [
            'report' => $report,
            'isAdministrator' => $isAdmin,
        ]);
    }

    public function reply(ReplyRequest $request): RedirectResponse
    {
        [$report, $isAdmin] = $this->resolveReport($request);

        $reply = $request->string('reply')->toString();
        $topic = $report->topic;

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $reply,
            'is_admin' => $isAdmin,
        ]);

        $adminUrl = route('report.show', ['administratorToken' => $report->administrator_token]);
        $reporterUrl = route('report.show', ['reporterToken' => $report->reporter_token]);

        $this->messengers->dispatch(
            $topic,
            sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id),
            $message,
            $adminUrl,
        );

        if ($isAdmin && $report->creator !== null && filter_var($report->creator, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($report->creator)->send(new ReportNotification(
                    subjectLine: sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id),
                    heading: sprintf('Update zu Meldung #%d', $report->id),
                    bodyHtml: $message->renderedBody(),
                    linkUrl: $reporterUrl,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to notify reporter', ['error' => $e->getMessage()]);
            }
        }

        $tokenParam = $isAdmin
            ? ['administratorToken' => $request->string('administratorToken', '')->toString()]
            : ['reporterToken' => $request->string('reporterToken', '')->toString()];

        return redirect()->route('report.show', $tokenParam);
    }

    /**
     * @return array{0: Report, 1: bool}
     */
    private function resolveReport(Request $request): array
    {
        $administratorToken = $request->string('administratorToken', '')->toString();
        $reporterToken = $request->string('reporterToken', '')->toString();

        if ($administratorToken !== '') {
            $report = Report::where('administrator_token', $administratorToken)->first();
            if ($report === null) {
                abort(404);
            }

            return [$report, true];
        }

        if ($reporterToken !== '') {
            $report = Report::where('reporter_token', $reporterToken)->first();
            if ($report === null) {
                abort(404);
            }

            return [$report, false];
        }

        abort(404);
    }
}
