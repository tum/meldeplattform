<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertTopicRequest;
use App\Models\Admin;
use App\Models\Field;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TopicAdminController extends Controller
{
    public function newTopic(int $topicID): View
    {
        return view('pages.new-topic', [
            'topicID' => $topicID,
        ]);
    }

    public function reportsOfTopic(int $topicID): View
    {
        /** @var Topic $topic */
        $topic = Topic::with(['fields', 'admins'])->findOrFail($topicID);
        $reports = Report::with('messages')->where('topic_id', $topic->id)->latest()->get();

        return view('pages.reports', [
            'topic' => $topic,
            'reports' => $reports,
        ]);
    }

    public function getTopic(int $topicID): JsonResponse
    {
        if ($topicID === 0) {
            return response()->json([
                'ID' => 0,
                'Name' => ['de' => '', 'en' => ''],
                'Summary' => ['de' => '', 'en' => ''],
                'Fields' => [],
                'Admins' => [],
                'Email' => '',
            ]);
        }

        /** @var Topic $topic */
        $topic = Topic::with(['fields', 'admins'])->findOrFail($topicID);

        return response()->json($this->serialize($topic));
    }

    public function upsertTopic(UpsertTopicRequest $request, int $topicID): JsonResponse
    {
        /** @var array{ID: int, Name: array{de?: string|null, en?: string|null}, Summary?: array{de?: string|null, en?: string|null}|null, Email?: string|null, Fields: list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}>, Admins?: list<array{UserID?: string|null}>|null} $payload */
        $payload = $request->validated();

        if ($payload['ID'] !== $topicID) {
            return response()->json(['error' => 'Topic ID mismatch'], 400);
        }

        $topic = DB::transaction(function () use ($topicID, $payload): Topic {
            /** @var Topic $topic */
            $topic = $topicID === 0 ? new Topic : Topic::findOrFail($topicID);

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

        return response()->json(['ID' => $topic->id, 'saved' => true]);
    }

    public function setStatus(Request $request, int $topicID, int $reportID): JsonResponse
    {
        $status = $request->string('s', '')->toString();
        $map = [
            'open' => Report::STATE_OPEN,
            'close' => Report::STATE_DONE,
            'spam' => Report::STATE_SPAM,
        ];
        if (! isset($map[$status])) {
            return response()->json(['error' => 'invalid status'], 400);
        }
        $report = Report::where('id', $reportID)->where('topic_id', $topicID)->first();
        if ($report === null) {
            return response()->json(['error' => 'report not found'], 404);
        }
        $report->state = $map[$status];
        $report->save();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{ID: int, Name: array{de: string, en: string}, Summary: array{de: string, en: string}, Email: string, Fields: list<array{ID: int, Name: array{de: string, en: string}, Description: array{de: string, en: string}, Type: string, Required: bool, Choices: list<string>}>, Admins: list<array{ID: int, UserID: string}>}
     */
    private function serialize(Topic $topic): array
    {
        /** @var list<array{ID: int, Name: array{de: string, en: string}, Description: array{de: string, en: string}, Type: string, Required: bool, Choices: list<string>}> $fields */
        $fields = array_values($topic->fields->map(static fn (Field $f): array => [
            'ID' => $f->id,
            'Name' => ['de' => $f->name_de, 'en' => $f->name_en],
            'Description' => ['de' => (string) $f->description_de, 'en' => (string) $f->description_en],
            'Type' => $f->type,
            'Required' => $f->required,
            'Choices' => $f->choices ?? [],
        ])->all());

        /** @var list<array{ID: int, UserID: string}> $admins */
        $admins = array_values($topic->admins->map(static fn (Admin $a): array => [
            'ID' => $a->id,
            'UserID' => $a->user_id,
        ])->all());

        return [
            'ID' => $topic->id,
            'Name' => ['de' => $topic->name_de, 'en' => $topic->name_en],
            'Summary' => ['de' => (string) $topic->summary_de, 'en' => (string) $topic->summary_en],
            'Email' => (string) $topic->email,
            'Fields' => $fields,
            'Admins' => $admins,
        ];
    }
}
