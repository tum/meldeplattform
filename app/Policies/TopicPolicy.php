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
     * Anyone authenticated may open the topic administration index — it lists
     * only the topics they can manage (global admins see all; topic-admins see
     * their own), scoped in SQL via Topic::scopeManageableBy.
     */
    public function viewAny(User $user): bool
    {
        return true;
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
