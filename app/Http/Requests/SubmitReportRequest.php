<?php

namespace App\Http\Requests;

use App\Models\Topic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    private ?Topic $cachedTopic = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Numeric field IDs end up as int array keys (PHP coerces "5" to 5),
     * so the key type is array-key rather than string.
     *
     * @return array<array-key, array<int, mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $rules = [
            'topic' => ['required', 'integer', Rule::exists('topics', 'id')],
            'email' => ['nullable', 'email:rfc'],
        ];

        $topic = $this->resolveTopic();
        if ($topic === null) {
            return $rules;
        }

        /** @var list<string> $extensions */
        $extensions = array_values(array_filter(
            Config::array('meldeplattform.allowed_extensions', []),
            'is_string',
        ));
        $maxKb = Config::integer('meldeplattform.max_upload_mb', 10) * 1024;
        $joined = implode(',', $extensions);
        // `extensions:` blocks rename attacks (evil.exe → evil.pdf in the
        // filename) and `mimes:` reinforces with server-side MIME detection
        // so a file whose content does not match its claimed extension is
        // rejected before it ever hits the disk.
        $extensionRule = 'extensions:'.$joined;
        $mimesRule = 'mimes:'.$joined;

        foreach ($topic->fields as $field) {
            $name = (string) $field->id;

            if (in_array($field->type, ['file', 'files'], true)) {
                $fileRules = ['file', $extensionRule, $mimesRule, "max:{$maxKb}"];

                if ($field->type === 'files') {
                    $rules[$name] = $field->required ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
                    $rules[$name.'.*'] = $fileRules;
                } else {
                    array_unshift($fileRules, $field->required ? 'required' : 'nullable');
                    $rules[$name] = $fileRules;
                }

                continue;
            }

            $rules[$name] = $field->required ? ['required', 'string'] : ['nullable', 'string'];
        }

        return $rules;
    }

    public function topic(): Topic
    {
        $topic = $this->resolveTopic();
        if ($topic === null) {
            // The `topic` rule above guarantees this is unreachable once
            // validation has run; the check here keeps the signature honest.
            abort(422);
        }

        return $topic;
    }

    public function emailOrNull(): ?string
    {
        $email = trim($this->string('email', '')->toString());

        return $email === '' ? null : $email;
    }

    private function resolveTopic(): ?Topic
    {
        if ($this->cachedTopic !== null) {
            return $this->cachedTopic;
        }

        $id = $this->integer('topic');
        if ($id <= 0) {
            return null;
        }

        return $this->cachedTopic = Topic::with('fields')->find($id);
    }
}
