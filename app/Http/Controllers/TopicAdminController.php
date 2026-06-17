<?php

namespace App\Http\Controllers;

use App\Actions\UpsertTopic;
use App\Enums\ReportState;
use App\Http\Requests\UpsertTopicRequest;
use App\Http\Resources\TopicResource;
use App\Models\Report;
use App\Models\Topic;
use App\Models\TopicView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TopicAdminController
{
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
        $reports = Report::with('messages')->where('topic_id', $topic->id)->latest()->get();

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

    /**
     * Cross-topic admin dashboard: every report in every topic the
     * authenticated user can view, sorted newest first. Uses the existing
     * TopicPolicy::view check to pick the topic set so the same access
     * rules apply as on the per-topic /reports/{topic} page.
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $topics = Topic::with('admins')->get()->filter(
            fn (Topic $t) => $user->can('view', $t),
        )->values();

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->all();

        $reports = Report::with(['topic', 'messages'])
            ->whereIn('topic_id', $topicIds)
            ->latest('updated_at')
            ->get();

        return view('pages.dashboard', [
            'topics' => $topics,
            'reports' => $reports,
        ]);
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
            'close' => ReportState::Done,
            'spam' => ReportState::Spam,
        ];
        if (! isset($map[$status])) {
            return response()->json(['error' => 'invalid status'], 400);
        }
        $report->state = $map[$status];
        // A status change is admin housekeeping, not new thread activity, so
        // it must not bump `updated_at` — that column drives the home-page
        // unread badge (reports.updated_at > topic_views.last_seen_at) and
        // re-surfacing a report the admin just triaged is misleading.
        $report->timestamps = false;
        $report->save();

        return response()->json(['ok' => true]);
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
        // Mirror setStatus(): a bulk status change is housekeeping, so leave
        // `updated_at` untouched to avoid inflating the unread badge. Drop to
        // the base query builder via toBase() because Eloquent's Builder
        // auto-injects `updated_at` into every update().
        $updated = Report::where('topic_id', $topic->id)
            ->whereIn('id', $ids)
            ->toBase()
            ->update(['state' => $newState->value]);

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
