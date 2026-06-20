<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the /users create+update form. The UID is taken from the body
 * on create and from the (alpha-num-constrained) route param on update; in
 * both cases it is trimmed before validation. Business-rule lockouts that
 * cannot be expressed as field rules — self-demote and pre-assigning global
 * admin to a user who has never logged in — are enforced in withValidator so
 * they surface as the same translated session errors as before.
 */
class UpsertUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the UID up front: trim it, and on update fold the route
     * param into the validated `uid` so both paths share one rule set.
     */
    protected function prepareForValidation(): void
    {
        $routeUid = $this->route('uid');
        $uid = is_string($routeUid) && $routeUid !== ''
            ? $routeUid
            : $this->string('uid', '')->toString();

        $this->merge(['uid' => trim($uid)]);
    }

    /**
     * @return array<string, array<int, mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'uid' => ['required', 'string', 'alpha_num'],
            'is_global_admin' => ['boolean'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:topics,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uid.required' => __('users_uid_required'),
            'uid.string' => __('users_uid_invalid'),
            'uid.alpha_num' => __('users_uid_invalid'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $uid = $this->uid();
            $isGlobal = $this->wantsGlobalAdmin();
            $actor = $this->user();
            $existingUser = User::where('uid', $uid)->first();

            // Lockout guard: a global admin must not be able to demote
            // themselves via this UI. They can still drop out of the env list
            // by editing config — that path is intentional ops work.
            if ($actor !== null && $actor->uid === $uid && ! $isGlobal && $existingUser?->is_global_admin) {
                $validator->errors()->add('is_global_admin', __('users_cannot_self_demote'));

                return;
            }

            // is_global_admin lives only on the users table, so it cannot be
            // pre-assigned before the user has ever logged in.
            if ($isGlobal && $existingUser === null) {
                $validator->errors()->add('is_global_admin', __('users_cannot_set_global_admin_pending'));
            }
        });
    }

    public function uid(): string
    {
        return $this->string('uid', '')->toString();
    }

    public function wantsGlobalAdmin(): bool
    {
        return $this->boolean('is_global_admin');
    }

    /**
     * The submitted topic IDs, deduplicated and constrained to positive
     * integers. (The `exists` rule has already proved they are real topics.)
     *
     * @return list<int>
     */
    public function topicIds(): array
    {
        $raw = $this->input('topic_ids', []);
        if (! is_array($raw)) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $raw,
        ), static fn (int $i): bool => $i > 0)));

        return $ids;
    }
}
