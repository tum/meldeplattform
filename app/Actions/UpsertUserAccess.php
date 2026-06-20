<?php

namespace App\Actions;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpsertUserAccess
{
    /**
     * Apply the global-admin flag and per-topic admin pivot for a UID in a
     * single transaction. Creates an `admins` row on demand so pre-assigning
     * access for someone who hasn't logged in yet works the same as updating
     * an existing user; drops the row entirely when no topics remain so it
     * doesn't linger as an empty pivot.
     *
     * Business-rule guards (self-demote / global-admin-on-pending-user) are
     * enforced upstream in UpsertUserRequest, so this action assumes a valid
     * combination.
     *
     * @param list<int> $topicIds
     */
    public function execute(string $uid, bool $isGlobalAdmin, array $topicIds): void
    {
        DB::transaction(function () use ($uid, $isGlobalAdmin, $topicIds): void {
            /** @var list<int> $validIds */
            $validIds = Topic::whereIn('id', $topicIds)->pluck('id')->all();

            if ($validIds !== []) {
                $admin = Admin::firstOrCreate(['user_id' => $uid]);
                $admin->topics()->sync($validIds);
            } else {
                // No topics selected → drop the admin assignment entirely
                // so the row doesn't linger as an empty pivot.
                Admin::where('user_id', $uid)->delete();
            }

            $existingUser = User::where('uid', $uid)->first();
            if ($existingUser !== null) {
                $existingUser->is_global_admin = $isGlobalAdmin;
                $existingUser->save();
            }
        });
    }
}
