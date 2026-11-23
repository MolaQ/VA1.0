<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Club;
use App\Models\Game;
use App\Models\User;

class GamePolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    /**
     * Staff and players see every match of their club; parents see matches of their children's teams.
     */
    public function view(User $user, Game $game): bool
    {
        if ($user->hasRole($game->club_id, Role::Admin, Role::Trainer, Role::Player)) {
            return true;
        }

        return $user->hasRole($game->club_id, Role::Parent)
            && $user->children()->where('players.club_id', $game->club_id)->exists();
    }

    public function create(User $user, Club $club): bool
    {
        return $user->isStaffOf($club);
    }

    public function update(User $user, Game $game): bool
    {
        return $user->isStaffOf($game->club_id);
    }

    /**
     * Manual coding / correcting of actions.
     */
    public function code(User $user, Game $game): bool
    {
        return $user->isStaffOf($game->club_id);
    }

    public function delete(User $user, Game $game): bool
    {
        return $user->isAdminOf($game->club_id);
    }
}
