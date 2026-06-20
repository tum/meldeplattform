<?php

namespace App\Http\Controllers;

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
    public function index(): View
    {
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
            ];
        }
        foreach ($admins as $admin) {
            $existing = $rows[$admin->user_id] ?? [
                'uid' => $admin->user_id,
                'user' => null,
                'admin' => null,
                'topics' => collect(),
            ];
            $existing['admin'] = $admin;
            $existing['topics'] = $admin->topics;
            $rows[$admin->user_id] = $existing;
        }
        ksort($rows);

        return view('pages.users.index', [
            'rows' => array_values($rows),
            'topics' => $topics,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $uid = $this->normalizeUid($request->string('uid', '')->toString());
        if ($uid === '') {
            return back()->withErrors(['uid' => __('users_uid_required')])->withInput();
        }
        if (! ctype_alnum($uid)) {
            return back()->withErrors(['uid' => __('users_uid_invalid')])->withInput();
        }

        return $this->persist($request, $uid);
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

    public function update(Request $request, string $uid): RedirectResponse
    {
        return $this->persist($request, $this->normalizeUid($uid));
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
     * Shared implementation for store/update: apply global-admin flag and
     * topic-admin pivot for the given UID. Creates an `admins` row on
     * demand so pre-assigning access for someone who hasn't logged in
     * yet works the same as updating an existing user.
     */
    private function persist(Request $request, string $uid): RedirectResponse
    {
        $isGlobal = $request->boolean('is_global_admin');

        $rawIds = $request->input('topic_ids', []);
        if (! is_array($rawIds)) {
            $rawIds = [];
        }
        /** @var list<int> $topicIds */
        $topicIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawIds,
        ), static fn (int $i): bool => $i > 0)));

        $actor = $request->user();
        $existingUser = User::where('uid', $uid)->first();

        // Lockout guard: a global admin must not be able to demote
        // themselves via this UI. They can still drop out of the env list
        // by editing config — that path is intentional ops work.
        if ($actor !== null && $actor->uid === $uid && ! $isGlobal && $existingUser?->is_global_admin) {
            return back()
                ->withErrors(['is_global_admin' => __('users_cannot_self_demote')])
                ->withInput();
        }

        // is_global_admin lives only on the users table, so it cannot be
        // pre-assigned before the user has ever logged in.
        if ($isGlobal && $existingUser === null) {
            return back()
                ->withErrors(['is_global_admin' => __('users_cannot_set_global_admin_pending')])
                ->withInput();
        }

        DB::transaction(function () use ($uid, $isGlobal, $topicIds, $existingUser): void {
            $validIds = Topic::whereIn('id', $topicIds)->pluck('id')->all();

            if ($validIds !== []) {
                $admin = Admin::firstOrCreate(['user_id' => $uid]);
                $admin->topics()->sync($validIds);
            } else {
                // No topics selected → drop the admin assignment entirely
                // so the row doesn't linger as an empty pivot.
                Admin::where('user_id', $uid)->delete();
            }

            if ($existingUser !== null) {
                $existingUser->is_global_admin = $isGlobal;
                $existingUser->save();
            }
        });

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
