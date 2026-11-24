<?php

use App\Enums\Role;
use App\Models\Club;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A club with one team, a small roster (#2, #13, #26 libero) and one match.
 *
 * @return array{club: Club, team: Team, game: Game, players: Collection<int, Player>}
 */
function clubWithGame(): array
{
    $club = Club::factory()->create();
    $team = Team::factory()->for($club)->create();

    $players = collect([[2, 'opposite'], [13, 'outside'], [26, 'libero']])
        ->map(fn ($p) => Player::factory()->for($club)->for($team)->create(['number' => $p[0], 'position' => $p[1]]));

    $game = Game::factory()->for($club)->for($team)->create();

    return compact('club', 'team', 'game', 'players');
}

function member(Club $club, Role ...$roles): User
{
    $user = User::factory()->create();

    foreach ($roles as $role) {
        $club->addMember($user, $role);
    }

    $user->switchClub($club);

    return $user->fresh();
}
