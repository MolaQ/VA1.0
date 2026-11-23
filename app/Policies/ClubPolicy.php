<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\User;

class ClubPolicy
{
    public function view(User $user, Club $club): bool
    {
        return $user->belongsToClub($club);
    }

    public function manage(User $user, Club $club): bool
    {
        return $user->isAdminOf($club);
    }
}
