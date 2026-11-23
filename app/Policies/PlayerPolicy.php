<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;

class PlayerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    public function view(User $user, Player $player): bool
    {
        if ($user->hasRole($player->club_id, Role::Admin, Role::Trainer, Role::Player)) {
            return true;
        }

        return $player->guardians()->whereKey($user->id)->exists();
    }

    public function create(User $user, Club $club): bool
    {
        return $user->isStaffOf($club);
    }

    public function update(User $user, Player $player): bool
    {
        return $user->isStaffOf($player->club_id);
    }

    public function delete(User $user, Player $player): bool
    {
        return $user->isAdminOf($player->club_id);
    }
}
