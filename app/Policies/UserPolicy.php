<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Gate for the /users management UI. Only global admins manage other
     * users; topic-admins stay scoped to their own topics via TopicPolicy.
     */
    public function manage(User $user): bool
    {
        return $user->isGlobalAdmin();
    }
}
