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

    public function update(User $user, Topic $topic): bool
    {
        $topic->loadMissing('admins');

        return $topic->isAdmin($user->uid);
    }

    public function view(User $user, Topic $topic): bool
    {
        return $this->update($user, $topic);
    }
}
