<?php

namespace App\Http\Requests;

use App\Enums\FieldType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'ID' => ['required', 'integer', 'min:0'],
            'Name' => ['required', 'array:de,en'],
            'Name.de' => ['nullable', 'string'],
            'Name.en' => ['nullable', 'string'],
            'Summary' => ['nullable', 'array:de,en'],
            'Summary.de' => ['nullable', 'string'],
            'Summary.en' => ['nullable', 'string'],
            'Email' => ['nullable', 'string'],

            'Fields' => ['required', 'array', 'min:1'],
            'Fields.*.ID' => ['nullable', 'integer'],
            'Fields.*.Name' => ['required', 'array:de,en'],
            'Fields.*.Name.de' => ['nullable', 'string'],
            'Fields.*.Name.en' => ['nullable', 'string'],
            'Fields.*.Description' => ['nullable', 'array:de,en'],
            'Fields.*.Description.de' => ['nullable', 'string'],
            'Fields.*.Description.en' => ['nullable', 'string'],
            'Fields.*.Type' => ['required', Rule::enum(FieldType::class)],
            'Fields.*.Required' => ['nullable', 'boolean'],
            'Fields.*.Choices' => ['nullable', 'array'],
            'Fields.*.Choices.*' => ['string'],

            'Admins' => ['nullable', 'array'],
            'Admins.*.ID' => ['nullable', 'integer'],
            'Admins.*.UserID' => ['nullable', 'string'],
        ];
    }
}
