<?php

namespace App\Http\Requests;

use App\Models\Topic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
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
            'topic' => ['required', 'integer', Rule::exists('topics', 'id')],
            'email' => ['nullable', 'email:rfc'],
        ];
    }

    public function topic(): Topic
    {
        /** @var Topic $topic */
        $topic = Topic::with('fields')->findOrFail($this->integer('topic'));

        return $topic;
    }

    public function emailOrNull(): ?string
    {
        $email = trim($this->string('email', '')->toString());

        return $email === '' ? null : $email;
    }
}
