<?php

namespace App\Http\Requests;

use App\Enums\FieldType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'Email' => ['nullable', 'email:rfc'],

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
            'Admins.*.UserID' => ['nullable', 'string', 'alpha_num'],

            'RequireLogin' => ['nullable', 'boolean'],
            'RetentionDays' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ];
    }

    /**
     * The per-language name keys are individually nullable (a topic needs a
     * name in at least one language, not both), but the editor always posts
     * both keys — empty strings included — so `required_without` can't catch
     * the all-empty case. Enforce "at least one non-blank name" here for the
     * topic and for every field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->bothBlank('Name.de', 'Name.en')) {
                $validator->errors()->add('Name.en', __('validation_topic_name_required'));
            }

            /** @var array<int, mixed> $fields */
            $fields = (array) $this->input('Fields', []);
            foreach (array_keys($fields) as $i) {
                // Info fields are display-only: they carry no label, so a name
                // is not required — but their formatted content must be present
                // in at least one language, or the block would render empty.
                if ($this->input("Fields.{$i}.Type") === FieldType::Info->value) {
                    if ($this->bothBlank("Fields.{$i}.Description.de", "Fields.{$i}.Description.en")) {
                        $validator->errors()->add("Fields.{$i}.Description.en", __('validation_field_text_required'));
                    }

                    continue;
                }

                if ($this->bothBlank("Fields.{$i}.Name.de", "Fields.{$i}.Name.en")) {
                    $validator->errors()->add("Fields.{$i}.Name.en", __('validation_field_name_required'));
                }
            }
        });
    }

    private function bothBlank(string $keyA, string $keyB): bool
    {
        return $this->isBlank($keyA) && $this->isBlank($keyB);
    }

    private function isBlank(string $key): bool
    {
        $value = $this->input($key);

        return ! is_string($value) || trim($value) === '';
    }
}
