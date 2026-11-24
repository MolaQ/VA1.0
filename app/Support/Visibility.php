<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Game;
use App\Models\User;

final class Visibility
{
    /**
     * Which player rows of a match box score the user may see.
     * Null means "all"; a parent (without another role) only sees their own children.
     *
     * @return array<int, int>|null
     */
    public static function playerIdsFor(User $user, Game $game): ?array
    {
        if ($user->is_super_admin || $user->hasRole($game->club_id, Role::Admin, Role::Trainer, Role::Player)) {
            return null;
        }

        return $user->children()
            ->where('players.club_id', $game->club_id)
            ->pluck('players.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function canSeeTeamTotals(User $user, Game $game): bool
    {
        return self::playerIdsFor($user, $game) === null;
    }
}
