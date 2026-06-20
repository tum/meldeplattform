<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Topic $resource
 */
class TopicResource extends JsonResource
{
    /**
     * Empty-Topic shape returned by the admin "new topic" form. The frontend
     * expects the same envelope as an existing topic, with zeroed/empty
     * values so the editor can populate them in place.
     */
    public static function skeleton(): self
    {
        $topic = new Topic;
        $topic->setRelation('fields', collect());
        $topic->setRelation('admins', collect());

        return new self($topic);
    }

    /**
     * @return array{ID: int, Name: array{de: string, en: string}, Summary: array{de: string, en: string}, Email: string, RequireLogin: bool, RetentionDays: int|null, Fields: list<array<string, mixed>>, Admins: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $topic = $this->resource;

        return [
            'ID' => (int) $topic->id,
            'Name' => [
                'de' => (string) $topic->name_de,
                'en' => (string) $topic->name_en,
            ],
            'Summary' => [
                'de' => (string) $topic->summary_de,
                'en' => (string) $topic->summary_en,
            ],
            'Email' => (string) $topic->email,
            'RequireLogin' => (bool) $topic->require_login,
            'RetentionDays' => $topic->retention_days,
            'Fields' => array_values(FieldResource::collection($topic->fields)->resolve($request)),
            'Admins' => array_values(AdminResource::collection($topic->admins)->resolve($request)),
        ];
    }
}
