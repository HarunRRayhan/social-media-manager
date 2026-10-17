<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Owners and admins manage members, social sets and API tokens.
     */
    public function manage(User $user, Team $team): bool
    {
        return in_array($user->teamRole($team), ['owner', 'admin'], true);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->teamRole($team) === 'owner';
    }
}
