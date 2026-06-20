<?php

namespace App\Http\Controllers;

use App\Actions\UpsertTopic;
use App\Enums\ReportState;
use App\Http\Requests\ReplyRequest;
use App\Http\Requests\UpsertTopicRequest;
use App\Http\Resources\TopicResource;
use App\Mail\ReportNotification;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\TopicView;
use App\Services\MessengerDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TopicAdminController
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

    public function create(): View
    {
        return view('pages.new-topic', ['topic' => null]);
    }

    public function edit(Topic $topic): View
    {
        return view('pages.new-topic', ['topic' => $topic]);
    }

    public function reportsOfTopic(Topic $topic, Request $request): View
    {
        $topic->load(['fields', 'admins']);
        $reports = Report::with('messages')
            ->where('topic_id', $topic->id)
            ->latest()
            ->limit(5000)
            ->get();

        // Mark the topic as just-seen for this admin so the home-page
        // unread badge clears.
        $user = $request->user();
        if ($user !== null) {
            TopicView::markSeen($user->id, $topic->id);
        }

        return view('pages.reports', [
            'topic' => $topic,
            'reports' => $reports,
        ]);
    }

    public function showReport(Topic $topic, Report $report, Request $request): View
    {
        // Scope binding ensures $report belongs to $topic.
        $report->load('messages.files', 'topic');
        AuditLog::record('report.accessed', $report);

        return view('pages.report', [
            'report' => $report,
            'isAdministrator' => true,
        ]);
    }

    public function replyToReport(ReplyRequest $request, Topic $topic, Report $report): RedirectResponse
    {
        $reply = $request->string('reply')->toString();

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $reply,
            'is_admin' => true,
        ]);

        // An administrator reply is implicit acknowledgement of the report
        // (EU Whistleblowing Directive 7-day window). Idempotent.
        $report->acknowledge();

        AuditLog::record('report.replied', $report);

        $adminUrl = route('admin.report.show', ['topic' => $topic->id, 'report' => $report->id]);
        $reporterUrl = route('report.show', ['reporterToken' => $report->reporter_token]);

        $this->messengers->dispatch(
            $topic,
            sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id),
            $message,
            $adminUrl,
        );

        if ($report->creator !== null && filter_var($report->creator, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($report->creator)->send(new ReportNotification(
                    subjectLine: sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id),
                    heading: sprintf('Update zu Meldung #%d', $report->id),
                    linkUrl: $reporterUrl,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to notify reporter', ['error' => $e->getMessage()]);
            }
        }

        return redirect()->route('admin.report.show', ['topic' => $topic->id, 'report' => $report->id]);
    }

    /**
     * Cross-topic admin dashboard. Reports are filtered and paginated in SQL,
     * scoped to the topics the authenticated user may manage (Topic scope +
     * TopicPolicy share the same rule). Filtering server-side means rows the
     * filter hides — including the `creator` column — are never shipped to the
     * browser, and pagination keeps the page bounded as report volume grows.
     *
     * Query params (all optional):
     *  - topic       int   restrict to a single manageable topic
     *  - hide_closed "1"   omit closed reports
     *  - hide_spam   "1"   omit spam reports
     *  - filters     "1"   marks a real filter submit; without it the page
     *                      uses the historical defaults (hide closed + spam)
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $manageable = Topic::query()->manageableBy($user)->orderBy('name_en');
        $topics = $manageable->get();

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->all();

        [$query, $selectedTopic, $hideClosed, $hideSpam] = $this->filteredReportsQuery($request, $topicIds);

        $reports = $query
            ->with(['topic', 'messages'])
            ->latest('updated_at')
            ->paginate(50)
            ->withQueryString();

        // Count overdue reports across ALL manageable topics (independent of the
        // current page and the hide filters) so the alert badge is accurate.
        $overdueCount = Report::query()->whereIn('topic_id', $topicIds)->overdueNow()->count();

        return view('pages.dashboard', [
            'topics' => $topics,
            'reports' => $reports,
            'selectedTopic' => $selectedTopic,
            'hideClosed' => $hideClosed,
            'hideSpam' => $hideSpam,
            'overdueCount' => $overdueCount,
        ]);
    }

    /**
     * Stream the filtered dashboard reports as CSV for audits / leadership
     * reporting (a feature commercial platforms offer). The export honours the
     * same manageable-topic scope and filters as the dashboard, and carries
     * only case metadata — never message bodies — so confidential allegation
     * content stays inside the authenticated UI. The export itself is audited.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        /** @var list<int> $topicIds */
        $topicIds = Topic::query()->manageableBy($user)->pluck('id')->all();

        [$query] = $this->filteredReportsQuery($request, $topicIds);

        AuditLog::record('reports.exported', null, [
            'topic' => $request->integer('topic') ?: 'all',
            'hide_closed' => $request->boolean('hide_closed'),
            'hide_spam' => $request->boolean('hide_spam'),
            'count' => (clone $query)->count(),
        ]);

        $filename = 'reports-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            // UTF-8 BOM so Excel renders umlauts in topic names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Topic', 'State', 'Created', 'Acknowledged', 'Closed',
                'Ack overdue', 'Feedback overdue', 'Messages', 'Reporter',
            ]);

            $query->with(['topic', 'messages'])
                ->latest('updated_at')
                ->chunk(200, function (Collection $reports) use ($out): void {
                    foreach ($reports as $report) {
                        /** @var Report $report */
                        fputcsv($out, [
                            $report->id,
                            $report->topic->name('en'),
                            $report->state->value,
                            $report->created_at?->toDateTimeString() ?? '',
                            $report->acknowledged_at?->toDateTimeString() ?? '',
                            $report->closed_at?->toDateTimeString() ?? '',
                            $report->isAcknowledgementOverdue() ? 'yes' : 'no',
                            $report->isFeedbackOverdue() ? 'yes' : 'no',
                            $report->messages->count(),
                            $report->creator ?? '',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Build the manageable-scoped, filtered report query shared by the
     * dashboard and its CSV export. Returns the query plus the resolved filter
     * state so the view can reflect it.
     *
     * @param list<int> $topicIds
     * @return array{0: Builder<Report>, 1: int, 2: bool, 3: bool}
     */
    private function filteredReportsQuery(Request $request, array $topicIds): array
    {
        // A fresh visit (no `filters` marker) applies the historical defaults
        // the old client-side filter shipped with: hide closed + hide spam.
        $filtersApplied = $request->boolean('filters');
        $hideClosed = $filtersApplied ? $request->boolean('hide_closed') : true;
        $hideSpam = $filtersApplied ? $request->boolean('hide_spam') : true;

        // Only honour a topic filter that is actually in the manageable set so
        // a hand-crafted `?topic=` can't surface another team's reports.
        $selectedTopic = $request->integer('topic');
        if ($selectedTopic > 0 && ! in_array($selectedTopic, $topicIds, true)) {
            $selectedTopic = 0;
        }

        $query = Report::query()->whereIn('topic_id', $topicIds);

        if ($selectedTopic > 0) {
            $query->where('topic_id', $selectedTopic);
        }
        if ($hideClosed) {
            $query->where('state', '!=', ReportState::Done->value);
        }
        if ($hideSpam) {
            $query->where('state', '!=', ReportState::Spam->value);
        }

        return [$query, $selectedTopic, $hideClosed, $hideSpam];
    }

    public function createSkeleton(): TopicResource
    {
        return TopicResource::skeleton();
    }

    public function show(Topic $topic): TopicResource
    {
        return TopicResource::make($topic->load(['fields', 'admins']));
    }

    public function store(UpsertTopicRequest $request, UpsertTopic $action): JsonResponse
    {
        return $this->save($request, null, $action);
    }

    public function update(UpsertTopicRequest $request, Topic $topic, UpsertTopic $action): JsonResponse
    {
        return $this->save($request, $topic, $action);
    }

    public function setStatus(Request $request, Topic $topic, Report $report): JsonResponse
    {
        $status = $request->string('s', '')->toString();
        $map = [
            'open' => ReportState::Open,
            'progress' => ReportState::InProgress,
            'close' => ReportState::Done,
            'spam' => ReportState::Spam,
        ];
        if (! isset($map[$status])) {
            return response()->json(['error' => 'invalid status'], 400);
        }
        // Capture the prior state before mutating so the audit entry can
        // record the exact transition.
        $from = $report->state;
        $newState = $map[$status];
        $report->state = $newState;
        // A status change is admin housekeeping, not new thread activity, so
        // it must not bump `updated_at` — that column drives the home-page
        // unread badge (reports.updated_at > topic_views.last_seen_at) and
        // re-surfacing a report the admin just triaged is misleading.
        $report->timestamps = false;
        // Moving a report to "In progress" is an explicit act of taking it on,
        // so treat it as acknowledgement too. (Reopen/Close/Spam do not: they
        // either move backwards or close the thread.) Timestamps are disabled
        // above, so acknowledge()'s save won't bump updated_at either.
        if ($newState === ReportState::InProgress) {
            $report->acknowledge();
        }
        $report->save();

        AuditLog::record('report.status_changed', $report, [
            'from' => $from->value,
            'to' => $report->state->value,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Explicitly mark a report acknowledged (EU Whistleblowing Directive
     * 7-day window) without changing its workflow state. Idempotent.
     */
    public function acknowledge(Request $request, Topic $topic, Report $report): JsonResponse
    {
        $report->acknowledge();
        AuditLog::record('report.acknowledged', $report);

        return response()->json(['ok' => true, 'acknowledged_at' => $report->acknowledged_at?->toIso8601String()]);
    }

    /**
     * Bulk variant of setStatus. Accepts `ids` (a list of report IDs scoped
     * to the bound Topic — anything outside is silently ignored) and the
     * same `s` short code. Returns the number of rows actually updated.
     */
    public function bulkSetStatus(Request $request, Topic $topic): JsonResponse
    {
        $status = $request->string('s', '')->toString();
        $map = [
            'open' => ReportState::Open,
            'progress' => ReportState::InProgress,
            'close' => ReportState::Done,
            'spam' => ReportState::Spam,
        ];
        if (! isset($map[$status])) {
            return response()->json(['error' => 'invalid status'], 400);
        }

        $rawIds = $request->input('ids', []);
        if (! is_array($rawIds)) {
            return response()->json(['error' => 'ids must be an array'], 400);
        }

        $ids = array_values(array_filter(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawIds,
        ), static fn (int $i): bool => $i > 0));

        if ($ids === []) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        $newState = $map[$status];
        $scoped = fn (): \Illuminate\Database\Query\Builder => Report::where('topic_id', $topic->id)
            ->whereIn('id', $ids)
            ->toBase();

        // Mirror setStatus(): a bulk status change is housekeeping, so leave
        // `updated_at` untouched to avoid inflating the unread badge. Drop to
        // the base query builder via toBase() because Eloquent's Builder
        // auto-injects `updated_at` into every update().
        $updated = $scoped()->update(['state' => $newState->value]);

        // The mass update bypasses the model's `updating` hook, so maintain the
        // retention anchor (`closed_at`) inline. Concluding (Done/Spam) stamps
        // it only where still null so the original conclusion date stands;
        // reopening (Open/InProgress) clears it.
        if ($newState === ReportState::Done || $newState === ReportState::Spam) {
            $scoped()->whereNull('closed_at')->update(['closed_at' => now()]);
        } else {
            $scoped()->whereNotNull('closed_at')->update(['closed_at' => null]);
        }

        // Mirror setStatus(): moving to InProgress is an explicit act of taking
        // on the report — treat as acknowledgement for the EU Directive 7-day
        // window. Stamp only rows that aren't already acknowledged.
        if ($newState === ReportState::InProgress) {
            $scoped()->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
        }

        // Bulk-status design: we record a SINGLE summary `report.bulk_status_changed`
        // row rather than one row per report. The update above runs as a single
        // mass-update query (no models are hydrated), so emitting per-report rows
        // would require re-loading the affected ids purely to log them. A summary
        // row carrying the target state, the affected ids and the count keeps the
        // hot bulk path cheap while still being auditable. Subject = the topic.
        if ($updated > 0) {
            AuditLog::record('report.bulk_status_changed', $topic, [
                'to' => $newState->value,
                'report_ids' => $ids,
                'count' => $updated,
            ]);
        }

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    private function save(UpsertTopicRequest $request, ?Topic $topic, UpsertTopic $action): JsonResponse
    {
        /** @var array{ID: int, Name: array{de?: string|null, en?: string|null}, Summary?: array{de?: string|null, en?: string|null}|null, Email?: string|null, Fields: list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}>, Admins?: list<array{UserID?: string|null}>|null} $payload */
        $payload = $request->validated();

        $expectedId = $topic === null ? 0 : $topic->id;
        if ($payload['ID'] !== $expectedId) {
            return response()->json(['error' => 'Topic ID mismatch'], 400);
        }

        $saved = $action->execute($topic, $payload);

        return response()->json(['ID' => $saved->id, 'saved' => true]);
    }
}
