<?php

namespace App\Actions;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Field;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;

class UpsertTopic
{
    /**
     * Persist a topic (creating it on null) with its fields and admin
     * assignments in a single transaction. Fields not present in the
     * payload are deleted; admin UIDs are firstOrCreate'd so pre-assigning
     * access for someone who hasn't logged in yet still works.
     *
     * @param array{ID: int, Name: array{de?: string|null, en?: string|null}, Summary?: array{de?: string|null, en?: string|null}|null, Email?: string|null, RequireLogin?: bool|null, RetentionDays?: int|null, Fields: list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}>, Admins?: list<array{UserID?: string|null}>|null} $payload
     */
    public function execute(?Topic $topic, array $payload): Topic
    {
        return DB::transaction(function () use ($topic, $payload): Topic {
            $topic ??= new Topic;

            $topic->name_de = (string) ($payload['Name']['de'] ?? '');
            $topic->name_en = (string) ($payload['Name']['en'] ?? '');
            $topic->summary_de = (string) ($payload['Summary']['de'] ?? '');
            $topic->summary_en = (string) ($payload['Summary']['en'] ?? '');
            $topic->email = (string) ($payload['Email'] ?? '');
            $topic->require_login = (bool) ($payload['RequireLogin'] ?? false);
            $retention = $payload['RetentionDays'] ?? null;
            $topic->retention_days = is_numeric($retention) ? (int) $retention : null;
            $topic->save();

            // wasRecentlyCreated is true only on the insert that just happened,
            // which distinguishes a freshly created topic from an update.
            $action = $topic->wasRecentlyCreated ? 'topic.created' : 'topic.updated';

            $this->syncFields($topic, $payload['Fields']);
            $this->syncAdmins($topic, $payload['Admins'] ?? []);

            AuditLog::record($action, $topic, ['topic_id' => $topic->id]);

            return $topic;
        });
    }

    /**
     * @param list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}> $fieldsPayload
     */
    private function syncFields(Topic $topic, array $fieldsPayload): void
    {
        /** @var list<int> $keepFieldIds */
        $keepFieldIds = [];
        $position = 0;
        foreach ($fieldsPayload as $f) {
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
    }

    /**
     * @param list<array{UserID?: string|null}> $adminsPayload
     */
    private function syncAdmins(Topic $topic, array $adminsPayload): void
    {
        /** @var list<int> $adminIds */
        $adminIds = [];
        foreach ($adminsPayload as $a) {
            $userId = trim((string) ($a['UserID'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $admin = Admin::firstOrCreate(['user_id' => $userId]);
            $adminIds[] = $admin->id;
        }
        $topic->admins()->sync($adminIds);

        // Remove Admin records that are no longer assigned to any topic so
        // they don't linger as invisible orphans in the database.
        Admin::doesntHave('topics')->delete();
    }
}
