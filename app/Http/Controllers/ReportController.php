<?php

namespace App\Http\Controllers;

use App\Mail\ReportNotification;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function show(Request $request): View
    {
        [$report, $isAdmin] = $this->resolveReport($request);

        $report->load('messages.files', 'topic');

        return view('pages.report', [
            'report' => $report,
            'isAdministrator' => $isAdmin,
        ]);
    }

    public function reply(Request $request): RedirectResponse
    {
        [$report, $isAdmin] = $this->resolveReport($request);

        $reply = trim($request->string('reply', '')->toString());
        if ($reply === '') {
            abort(400, 'empty reply');
        }

        $topic = $report->topic;

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $reply,
            'is_admin' => $isAdmin,
        ]);

        $baseUrl = rtrim(Config::string('app.url'), '/');
        $adminUrl = $baseUrl.'/report?administratorToken='.$report->administrator_token;
        $reporterUrl = $baseUrl.'/report?reporterToken='.$report->reporter_token;

        MessengerDispatcher::dispatch(
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
            ? 'administratorToken='.$request->string('administratorToken', '')->toString()
            : 'reporterToken='.$request->string('reporterToken', '')->toString();

        return redirect('/report?'.$tokenParam);
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
