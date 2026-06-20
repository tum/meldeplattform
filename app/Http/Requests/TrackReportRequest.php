<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TrackReportRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:100'],
        ];
    }
}
