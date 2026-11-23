<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameForm;
use App\Livewire\Games\GameShow;
use App\Models\Club;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;
use App\Services\ActionIngestor;
use App\Support\Visibility;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('sends users without a club to onboarding', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/onboarding');
});

it('renders every main screen for a club admin', function (string $url) {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $url = str_replace(['{game}', '{player}'], [$game->id, $players[0]->id], $url);

    $this->actingAs(member($club, Role::Admin))->get($url)->assertOk();
})->with(['/dashboard', '/games', '/games/create', '/games/{game}', '/games/{game}/edit', '/players', '/players/{player}', '/club/members', '/club/teams', '/club/integrations', '/club/settings', '/profile']);

it('keeps the club admin panel away from coaches, players and parents', function (Role $role) {
    ['club' => $club] = clubWithGame();

    $this->actingAs(member($club, $role))->get('/club/members')->assertForbidden();
})->with([Role::Trainer, Role::Player, Role::Parent]);

it('hides matches of other clubs', function () {
    ['game' => $game] = clubWithGame();
    $outsider = member(Club::factory()->create(), Role::Admin);

    $this->actingAs($outsider)->get("/games/{$game->id}")->assertNotFound();
});

it('shows a parent only their own child', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $parent = member($club, Role::Parent);
    $parent->children()->attach($players[2]->id);

    expect(Visibility::playerIdsFor($parent, $game))->toBe([$players[2]->id])
        ->and(Visibility::playerIdsFor(member($club, Role::Player), $game))->toBeNull();

    $this->actingAs($parent)->get("/games/{$game->id}")
        ->assertOk()
        ->assertSee($players[2]->last_name)
        ->assertDontSee($players[0]->last_name);

    $this->actingAs($parent)->get("/players/{$players[0]->id}")->assertForbidden();
    $this->actingAs($parent)->get("/players/{$players[2]->id}")->assertOk();
});

it('lets a coach code actions manually but not a player', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();

    Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameShow::class, ['game' => $game])
        ->call('record', $players[0]->id, 'attack', '#', 125.5)
        ->assertOk();

    expect($game->actions()->first())
        ->skill->value->toBe('attack')
        ->video_time->toBe(125.5)
        ->source->value->toBe('manual');

    Livewire::actingAs(member($club, Role::Player))
        ->test(GameShow::class, ['game' => $game])
        ->call('record', $players[0]->id, 'attack', '#', 10.0)
        ->assertForbidden();
});

it('returns video clips behind a stat cell', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $game->actions()->create(['club_id' => $club->id, 'player_id' => $players[0]->id, 'skill' => 'attack', 'grade' => '#', 'video_time' => 300]);
    $game->actions()->create(['club_id' => $club->id, 'player_id' => $players[0]->id, 'skill' => 'attack', 'grade' => '=', 'video_time' => 100]);

    $component = Livewire::actingAs(member($club, Role::Player))->test(GameShow::class, ['game' => $game]);

    expect($component->instance()->clips($players[0]->id, 'attack', '#'))->toHaveCount(1)
        ->and($component->instance()->clips($players[0]->id, 'attack'))->toHaveCount(2)
        ->and($component->instance()->clips($players[0]->id, 'points')[0]['t'])->toBe(300.0);
});

it('lets a club admin delete a match with its actions', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $admin = member($club, Role::Admin);
    $game->sets()->create(['number' => 1, 'score_us' => 25, 'score_them' => 20]);
    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 15],
    ], ActionSource::Ai);
    expect(GameAction::where('game_id', $game->id)->count())->toBe(1);

    Livewire::actingAs($admin)->test(GameForm::class, ['game' => $game])
        ->assertSee(__('Delete match'))
        ->call('delete')
        ->assertRedirect(route('games.index'));

    expect(Game::find($game->id))->toBeNull()
        ->and(GameAction::where('game_id', $game->id)->count())->toBe(0);
});

it('does not let a trainer delete a match', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $trainer = member($club, Role::Trainer);

    Livewire::actingAs($trainer)->test(GameForm::class, ['game' => $game])
        ->assertDontSee(__('Delete match'))
        ->call('delete')
        ->assertForbidden();

    expect(Game::find($game->id))->not->toBeNull();
});
