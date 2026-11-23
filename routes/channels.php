<?php

use App\Models\Club;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id) {
    return (string) $user->id === (string) $id;
});

Broadcast::channel('games.{game}', function (User $user, Game $game) {
    return $user->can('view', $game);
});

Broadcast::channel('clubs.{club}', function (User $user, Club $club) {
    return $user->belongsToClub($club);
});

// Presence: who is online in the club right now.
Broadcast::channel('online.clubs.{club}', function (User $user, Club $club) {
    if (! $user->belongsToClub($club)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'initials' => $user->initials(),
        'role' => $user->is_super_admin ? 'Super admin' : $user->primaryRole($club)?->label(),
    ];
});
