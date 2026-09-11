<?php

namespace App\Policies;

use App\Models\Topic;
use App\Models\User;

class TopicPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isGlobalAdmin() ? true : null;
    }

    public function create(User $user): bool
    {
        // Only global admins create topics. `before()` already returns true
        // for them; topic-admins fall through to this `false`.
        return false;
    }

    /**
     * Gate for the admin surface as a whole: the topic index, the dashboard
     * and the editor's helper endpoints. Open to anyone who administers at
     * least one topic (global admins pass via before()); what they then see
     * is scoped in SQL via Topic::scopeManageableBy. A regular signed-in
     * user — every TUM member can sign in, since topics may require login
     * to report — has nothing to manage and is refused outright.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, Topic $topic): bool
    {
        $topic->loadMissing('admins');

        return $topic->isAdmin($user->uid);
    }

    public function view(User $user, Topic $topic): bool
    {
        return $this->update($user, $topic);
    }

    /**
     * Deleting a topic is destructive (it cascades away its fields and admin
     * links) and is reserved for global admins — `before()` returns true for
     * them, so a topic-admin falls through to this `false`. The controller
     * additionally refuses to delete a topic that still holds reports.
     */
    public function delete(User $user, Topic $topic): bool
    {
        return false;
    }
}
