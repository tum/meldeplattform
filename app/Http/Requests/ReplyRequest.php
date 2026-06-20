<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplyRequest extends FormRequest
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
            // TrimStrings middleware has already trimmed the input; `required`
            // therefore rejects empty and whitespace-only replies. `max` caps
            // the body (messages.content is longText) so a single request can't
            // ship a multi-megabyte payload that amplifies markdown rendering.
            'reply' => ['required', 'string', 'max:50000'],
        ];
    }
}
