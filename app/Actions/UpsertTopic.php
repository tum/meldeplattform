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
     * @param array{ID: int, Name: array{de?: string|null, en?: string|null}, Summary?: array{de?: string|null, en?: string|null}|null, Email?: string|null, RequireLogin?: bool|null, RetentionDays?: int|null, Contacts?: array<string, mixed>|null, Fields: list<array{ID?: int|null, Name: array{de?: string|null, en?: string|null}, Description?: array{de?: string|null, en?: string|null}|null, Type: string, Required?: bool|null, Choices?: list<string>|null}>, Admins?: list<array{UserID?: string|null}>|null} $payload
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
            $topic->contacts = $this->buildContacts($topic->contacts, $payload['Contacts'] ?? []);
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
     * Fold the editor's notification-channel input into the topic's existing
     * `contacts` JSON. Only the keys the editor manages (webhook, otrs) are
     * rewritten; anything else already there — e.g. a power-user
     * `email.target` set directly in the DB — is preserved. A topic opts into
     * OTRS purely by the presence of an `otrs` object: an empty one routes to
     * the global default queue, `{queue: …}` overrides it. An all-empty result
     * collapses to null so a topic with no channels keeps a clean column.
     *
     * @param array<string, array<string, string>>|null $existing
     * @param array<string, mixed> $input
     * @return array<string, array<string, string>>|null
     */
    private function buildContacts(?array $existing, array $input): ?array
    {
        $contacts = is_array($existing) ? $existing : [];

        $webhook = $input['Webhook'] ?? '';
        $webhook = is_string($webhook) ? trim($webhook) : '';
        if ($webhook !== '') {
            $contacts['webhook'] = ['target' => $webhook];
        } else {
            unset($contacts['webhook']);
        }

        $otrs = $input['Otrs'] ?? [];
        $otrs = is_array($otrs) ? $otrs : [];
        if ((bool) ($otrs['Enabled'] ?? false)) {
            $queue = $otrs['Queue'] ?? '';
            $queue = is_string($queue) ? trim($queue) : '';
            $contacts['otrs'] = $queue !== '' ? ['queue' => $queue] : [];
        } else {
            unset($contacts['otrs']);
        }

        return $contacts === [] ? null : $contacts;
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
            // Restrict lookup to this topic — a field ID from a different topic
            // simply creates a new field (safe) without loading cross-topic data.
            $field = ($fieldId > 0) ? Field::where('id', $fieldId)->where('topic_id', $topic->id)->first() : null;
            $field ??= new Field(['topic_id' => $topic->id]);

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
        $syncedUserIds = [];
        /** @var list<int> $adminIds */
        $adminIds = [];
        foreach ($adminsPayload as $a) {
            $userId = trim((string) ($a['UserID'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $admin = Admin::firstOrCreate(['user_id' => $userId]);
            $adminIds[] = $admin->id;
            $syncedUserIds[] = $userId;
        }
        $topic->admins()->sync($adminIds);

        // Only remove admins whose UIDs were touched by this sync and who now
        // have no topic assignments — avoids a race where a global sweep deletes
        // an admin mid-sync in a concurrent request.
        if ($syncedUserIds !== []) {
            Admin::whereIn('user_id', $syncedUserIds)->doesntHave('topics')->delete();
        }
    }
}
