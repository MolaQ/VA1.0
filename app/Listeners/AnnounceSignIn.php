<?php

namespace App\Listeners;

use App\Events\ClubActivity;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class AnnounceSignIn
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->current_club_id === null) {
            return;
        }

        ClubActivity::dispatch($user->current_club_id, ClubActivity::USER_SIGNED_IN, ['user' => $user->name], $user->id, null, 'staff');
    }
}
