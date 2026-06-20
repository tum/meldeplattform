<?php

namespace App\Http\Requests;

use App\Enums\FieldType;
use App\Models\Field;
use App\Models\Topic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    /** Upper bound on any single free-text field value (DoS amplification guard). */
    private const MAX_VALUE_LENGTH = 50000;

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

        // An audio field accepts only the audio allowlist (oral reporting),
        // intersected with the global allowlist so MIME detection stays valid.
        /** @var list<string> $audioExtensions */
        $audioExtensions = array_values(array_intersect(
            array_values(array_filter(
                Config::array('meldeplattform.allowed_audio_extensions', []),
                'is_string',
            )),
            $extensions,
        ));
        $audioJoined = implode(',', $audioExtensions);

        foreach ($topic->fields as $field) {
            $name = (string) $field->id;

            if ($field->type->isFileUpload()) {
                if ($field->type->isAudio() && $audioJoined !== '') {
                    $fileRules = ['file', 'extensions:'.$audioJoined, 'mimes:'.$audioJoined, "max:{$maxKb}"];
                } else {
                    $fileRules = ['file', $extensionRule, $mimesRule, "max:{$maxKb}"];
                }

                if ($field->type === FieldType::Files) {
                    $rules[$name] = $field->required ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
                    $rules[$name.'.*'] = $fileRules;
                } else {
                    array_unshift($fileRules, $field->required ? 'required' : 'nullable');
                    $rules[$name] = $fileRules;
                }

                continue;
            }

            $rules[$name] = array_merge(
                [$field->required ? 'required' : 'nullable'],
                $this->valueRules($field),
            );
        }

        return $rules;
    }

    /**
     * Type-specific value rules for a non-file field. The declared FieldType
     * drives server-side validation so the editor's type choice is actually
     * enforced (not just an HTML5 input hint a client can bypass). A leading
     * `required`/`nullable` is prepended by the caller; `nullable` short-
     * circuits these for empty input.
     *
     * @return list<string|ValidationRule>
     */
    private function valueRules(Field $field): array
    {
        $max = 'max:'.self::MAX_VALUE_LENGTH;

        switch ($field->type) {
            case FieldType::Email:
                return ['string', 'email:rfc', $max];
            case FieldType::Number:
                return ['numeric'];
            case FieldType::Date:
                return ['date'];
            case FieldType::Url:
                return ['string', 'url', $max];
            case FieldType::Select:
                /** @var list<string> $choices */
                $choices = array_values(array_filter(
                    is_array($field->choices) ? $field->choices : [],
                    'is_string',
                ));

                // Only constrain to the configured options when there are
                // any; a choice-less select degrades to a free string rather
                // than rejecting every value.
                return $choices === [] ? ['string', $max] : ['string', Rule::in($choices)];
            case FieldType::Text:
            case FieldType::Textarea:
            case FieldType::Checkbox:
            default:
                return ['string', $max];
        }
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
