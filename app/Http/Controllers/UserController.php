<?php

namespace App\Http\Controllers;

use App\Actions\UpsertUserAccess;
use App\Http\Requests\UpsertUserRequest;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Central user-management UI. Lists every UID known to the platform —
 * both authenticated users (rows in `users`) and pre-assigned admins
 * (rows in `admins` whose `user_id` hasn't logged in yet) — and lets a
 * global admin toggle each user's global-admin flag and per-topic
 * access from one place.
 */
class UserController
{
    public function index(Request $request): View
    {
        $q = trim($request->string('q')->toString());

        $users = User::orderBy('uid')->get();
        $admins = Admin::with('topics:id,name_de,name_en')->orderBy('user_id')->get();
        $topics = Topic::orderBy('name_en')->get(['id', 'name_de', 'name_en']);

        // Build a unified [uid => row] map merging user identity, admin
        // assignment, and per-topic pivots — used by the index view to
        // render one row per distinct UID regardless of which table the
        // entry originated in.
        $rows = [];
        foreach ($users as $user) {
            $rows[$user->uid] = [
                'uid' => $user->uid,
                'user' => $user,
                'admin' => null,
                'topics' => collect(),
                // Pre-built lowercase search key (uid + name + email) so the
                // optional filter below is a plain string match. Built here,
                // where $user is precisely typed, rather than re-derived from
                // the loosely-typed merged row.
                'search' => mb_strtolower(trim($user->uid.' '.($user->name ?? '').' '.($user->email ?? ''))),
            ];
        }
        foreach ($admins as $admin) {
            $existing = $rows[$admin->user_id] ?? [
                'uid' => $admin->user_id,
                'user' => null,
                'admin' => null,
                'topics' => collect(),
                'search' => mb_strtolower($admin->user_id),
            ];
            $existing['admin'] = $admin;
            $existing['topics'] = $admin->topics;
            $rows[$admin->user_id] = $existing;
        }
        ksort($rows);

        // Optional search over UID, name or email. The user/admin set is small,
        // so filtering after the merge keeps the two-table union simple.
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_filter(
                $rows,
                static fn (array $row): bool => str_contains((string) $row['search'], $needle),
            );
        }

        return view('pages.users.index', [
            'rows' => array_values($rows),
            'topics' => $topics,
            'q' => $q,
        ]);
    }

    public function store(UpsertUserRequest $request, UpsertUserAccess $action): RedirectResponse
    {
        return $this->persist($request, $action);
    }

    public function edit(string $uid): View
    {
        $uid = $this->normalizeUid($uid);
        $user = User::where('uid', $uid)->first();
        $admin = Admin::with('topics')->where('user_id', $uid)->first();
        $topics = Topic::orderBy('name_en')->get(['id', 'name_de', 'name_en']);

        return view('pages.users.edit', [
            'uid' => $uid,
            'user' => $user,
            'admin' => $admin,
            'topics' => $topics,
            'assignedTopicIds' => $admin?->topics->pluck('id')->all() ?? [],
        ]);
    }

    public function update(UpsertUserRequest $request, UpsertUserAccess $action): RedirectResponse
    {
        return $this->persist($request, $action);
    }

    public function destroy(Request $request, string $uid): RedirectResponse
    {
        $uid = $this->normalizeUid($uid);

        if ($request->user()?->uid === $uid) {
            return redirect()->route('users.index')
                ->withErrors(['uid' => __('users_cannot_self_revoke')]);
        }

        DB::transaction(function () use ($uid): void {
            // cascadeOnDelete on topic_admins clears the per-topic pivot.
            Admin::where('user_id', $uid)->delete();
            User::where('uid', $uid)->update(['is_global_admin' => false]);
        });

        AuditLog::record('admin.revoked', null, ['target_uid' => $uid]);

        return redirect()->route('users.index')
            ->with('flash.success', __('users_revoked', ['uid' => $uid]));
    }

    /**
     * Shared implementation for store/update. Validation (UID shape) and the
     * self-demote / global-admin-on-pending-user lockouts are enforced in
     * UpsertUserRequest; the topic-sync + flag persistence lives in
     * UpsertUserAccess. This method just wires the two together.
     */
    private function persist(UpsertUserRequest $request, UpsertUserAccess $action): RedirectResponse
    {
        $uid = $request->uid();
        $isGlobal = $request->wantsGlobalAdmin();
        $topicIds = $request->topicIds();

        $action->execute($uid, $isGlobal, $topicIds);

        AuditLog::record('admin.granted', null, [
            'target_uid' => $uid,
            'is_global_admin' => $isGlobal,
            'topic_ids' => $topicIds,
        ]);

        return redirect()->route('users.index')
            ->with('flash.success', __('users_saved', ['uid' => $uid]));
    }

    private function normalizeUid(string $uid): string
    {
        return trim($uid);
    }
}
