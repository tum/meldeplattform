<?php

namespace App\Http\Controllers;

use App\Enums\ReportState;
use App\Http\Requests\UpsertTopicRequest;
use App\Http\Resources\TopicResource;
use App\Models\Admin;
use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use App\Models\TopicView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function createSkeleton(): TopicResource
    {
        return TopicResource::skeleton();
    }

    public function show(Topic $topic): TopicResource
    {
        return TopicResource::make($topic->load(['fields', 'admins']));
    }

    public function store(UpsertTopicRequest $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(UpsertTopicRequest $request, Topic $topic): JsonResponse
    {
        return $this->save($request, $topic);
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
        $report->save();

        return response()->json(['ok' => true]);
    }

    private function save(UpsertTopicRequest $request, ?Topic $topic): JsonResponse
    {
        /** @var array{ID: int, Name: array{de?: string|null, en?: string|null}, Summary?: array{de?: string|null, en?: string|null}|null, Email?: string|null, Fields: list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}>, Admins?: list<array{UserID?: string|null}>|null} $payload */
        $payload = $request->validated();

        $expectedId = $topic === null ? 0 : $topic->id;
        if ($payload['ID'] !== $expectedId) {
            return response()->json(['error' => 'Topic ID mismatch'], 400);
        }

        $saved = DB::transaction(function () use ($topic, $payload): Topic {
            $topic ??= new Topic;

            $topic->name_de = (string) ($payload['Name']['de'] ?? '');
            $topic->name_en = (string) ($payload['Name']['en'] ?? '');
            $topic->summary_de = (string) ($payload['Summary']['de'] ?? '');
            $topic->summary_en = (string) ($payload['Summary']['en'] ?? '');
            $topic->email = (string) ($payload['Email'] ?? '');
            $topic->save();

            /** @var list<int> $keepFieldIds */
            $keepFieldIds = [];
            $position = 0;
            foreach ($payload['Fields'] as $f) {
                $fieldId = (int) ($f['ID'] ?? 0);
                $field = $fieldId > 0 ? Field::find($fieldId) : null;
                if ($field === null || $field->topic_id !== $topic->id) {
                    $field = new Field(['topic_id' => $topic->id]);
                }

                $field->fill([
                    'topic_id' => $topic->id,
                    'name_de' => (string) ($f['Name']['de'] ?? ''),
                    'name_en' => (string) ($f['Name']['en'] ?? ''),
                    'description_de' => (string) ($f['Description']['de'] ?? ''),
                    'description_en' => (string) ($f['Description']['en'] ?? ''),
                    'type' => $f['Type'],
                    'required' => (bool) ($f['Required'] ?? false),
                    'choices' => $f['Choices'] ?? [],
                    'position' => $position++,
                ]);
                $field->save();
                $keepFieldIds[] = $field->id;
            }
            $topic->fields()->whereNotIn('id', $keepFieldIds)->delete();

            /** @var list<int> $adminIds */
            $adminIds = [];
            foreach ($payload['Admins'] ?? [] as $a) {
                $userId = trim((string) ($a['UserID'] ?? ''));
                if ($userId === '') {
                    continue;
                }
                $admin = Admin::firstOrCreate(['user_id' => $userId]);
                $adminIds[] = $admin->id;
            }
            $topic->admins()->sync($adminIds);

            return $topic;
        });

        return response()->json(['ID' => $saved->id, 'saved' => true]);
    }
}
