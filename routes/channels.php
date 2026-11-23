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
