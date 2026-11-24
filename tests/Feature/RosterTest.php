<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Club\Teams;
use App\Livewire\Players\PlayerIndex;
use App\Models\Player;
use App\Models\Team;
use App\Services\ActionIngestor;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('adds a player with name, position and number', function () {
    ['club' => $club, 'team' => $team] = clubWithGame();

    Livewire::actingAs(member($club, Role::Trainer))
        ->test(PlayerIndex::class)
        ->set('first_name', 'Jan')->set('last_name', 'Kowalski')
        ->set('position', 'setter')->set('number', 7)->set('team_id', $team->id)
        ->call('save')
        ->assertHasNoErrors();

    $player = Player::query()->where('number', 7)->sole();
    expect($player->full_name)->toBe('Jan Kowalski')
        ->and($player->position?->value)->toBe('setter')
        ->and($player->team_id)->toBe($team->id);
});

it('keeps jersey numbers unique within a team', function () {
    ['club' => $club, 'team' => $team, 'players' => $players] = clubWithGame();
    $other = Team::factory()->for($club)->create();
    $trainer = member($club, Role::Trainer);

    // #13 is taken in this team...
    Livewire::actingAs($trainer)->test(PlayerIndex::class)
        ->set('first_name', 'Nowy')->set('number', 13)->set('team_id', $team->id)
        ->call('save')
        ->assertHasErrors(['number' => 'unique']);

    // ...but free in another team
    Livewire::actingAs($trainer)->test(PlayerIndex::class)
        ->set('first_name', 'Nowy')->set('number', 13)->set('team_id', $other->id)
        ->call('save')
        ->assertHasNoErrors();

    // editing a player keeps their own number
    Livewire::actingAs($trainer)->test(PlayerIndex::class)
        ->call('edit', $players[1]->id)
        ->set('last_name', 'Zmieniony')
        ->call('save')
        ->assertHasNoErrors();

    expect($players[1]->fresh()->last_name)->toBe('Zmieniony');
});

it('deletes a player without match history and only deactivates one with actions', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    app(ActionIngestor::class)->ingest($game, [
        ['external_id' => 'a1', 'set' => 1, 'player_number' => 2, 'skill' => 'serve', 'grade' => '#'],
    ], ActionSource::Manual);

    $admin = member($club, Role::Admin);

    Livewire::actingAs($admin)->test(PlayerIndex::class)->call('delete', $players[1]->id);
    expect(Player::query()->find($players[1]->id))->toBeNull();

    Livewire::actingAs($admin)->test(PlayerIndex::class)->call('delete', $players[0]->id);
    expect($players[0]->fresh())->not->toBeNull()
        ->and($players[0]->fresh()->is_active)->toBeFalse();
});

it('does not let a trainer delete players', function () {
    ['club' => $club, 'players' => $players] = clubWithGame();

    Livewire::actingAs(member($club, Role::Trainer))->test(PlayerIndex::class)
        ->call('delete', $players[1]->id)
        ->assertForbidden();

    expect($players[1]->fresh())->not->toBeNull();
});

it('filters the roster by team', function () {
    ['club' => $club, 'players' => $players] = clubWithGame();
    $other = Team::factory()->for($club)->create(['name' => 'Kadeci']);
    $cadet = Player::factory()->for($club)->for($other)->create(['first_name' => 'Kadet', 'last_name' => 'Testowy', 'number' => 99]);

    Livewire::actingAs(member($club, Role::Trainer))
        ->withQueryParams(['team' => $other->id])
        ->test(PlayerIndex::class)
        ->assertSee($cadet->full_name)
        ->assertDontSee($players[0]->full_name);
});

it('creates, renames and deletes a team', function () {
    ['club' => $club] = clubWithGame();
    $admin = member($club, Role::Admin);

    Livewire::actingAs($admin)->test(Teams::class)
        ->set('name', 'Rezerwy')->set('category', 'U19')
        ->call('save')->assertHasNoErrors();

    $team = $club->teams()->where('name', 'Rezerwy')->sole();
    $player = Player::factory()->for($club)->for($team)->create(['number' => 5]);

    Livewire::actingAs($admin)->test(Teams::class)
        ->call('edit', $team->id)->set('name', 'Rezerwy A')->call('save');
    expect($team->fresh()->name)->toBe('Rezerwy A');

    Livewire::actingAs($admin)->test(Teams::class)->call('delete', $team->id);
    expect(Team::query()->find($team->id))->toBeNull()
        ->and($player->fresh()->team_id)->toBeNull();
});
