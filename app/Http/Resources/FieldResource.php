<?php

namespace App\Http\Resources;

use App\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Field $resource
 */
class FieldResource extends JsonResource
{
    /**
     * @return array{ID: int, Name: array{de: string, en: string}, Description: array{de: string, en: string}, Type: string, Required: bool, Choices: list<string>}
     */
    public function toArray(Request $request): array
    {
        $field = $this->resource;

        return [
            'ID' => $field->id,
            'Name' => [
                'de' => $field->name_de,
                'en' => $field->name_en,
            ],
            'Description' => [
                'de' => (string) $field->description_de,
                'en' => (string) $field->description_en,
            ],
            'Type' => $field->type->value,
            'Required' => $field->required,
            'Choices' => $field->choices ?? [],
        ];
    }
}
