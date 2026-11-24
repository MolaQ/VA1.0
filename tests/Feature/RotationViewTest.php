<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameShow;
use App\Models\GameRally;
use App\Models\Player;
use App\Stats\RotationTimeline;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

/**
 * Set 1 with a starting lineup (#1..#6, #3 and #6 middles) and two liberos (#20 reception, #21 defence).
 *
 * @return array<string, mixed>
 */
function gameWithRotation(): array
{
    ['club' => $club, 'team' => $team, 'game' => $game] = clubWithGame();
    $make = fn (int $n, string $pos) => Player::factory()->for($club)->for($team)->create(['number' => $n, 'position' => $pos]);
    $lineup = [1 => $make(11, 'setter'), 2 => $make(12, 'outside'), 3 => $make(13, 'middle'), 4 => $make(14, 'opposite'), 5 => $make(15, 'outside'), 6 => $make(16, 'middle')];
    $reception = $make(20, 'libero');
    $defence = $make(21, 'libero');

    $set = $game->sets()->create(['number' => 1, 'we_serve_first' => true, 'libero_reception_id' => $reception->id, 'libero_defense_id' => $defence->id]);
    foreach ($lineup as $pos => $player) {
        $set->lineups()->create(['player_id' => $player->id, 'rotation_position' => $pos]);
    }

    foreach ([[1, 'us', 'them', 10], [2, 'them', 'us', 40], [3, 'us', 'us', 70]] as [$n, $server, $winner, $t]) {
        GameRally::create(['game_id' => $game->id, 'game_set_id' => $set->id, 'number' => $n, 'server' => $server, 'winner' => $winner, 'video_time' => $t, 'source' => ActionSource::Ai]);
    }

    return compact('club', 'game', 'set', 'lineup', 'reception', 'defence');
}

it('builds the rotation of every rally with the right libero', function () {
    ['game' => $game, 'lineup' => $lineup, 'reception' => $reception, 'defence' => $defence] = gameWithRotation();

    $data = app(RotationTimeline::class)->build($game);
    [$first, $second, $third] = $data['rallies'];

    // rally 1: we serve, rotation 1: middle #16 (position VI) is in zone 6 -> defence libero
    expect($first)->toMatchArray(['t' => 10.0, 'rot' => 1, 'serve' => 'us', 'us' => 0, 'them' => 0])
        ->and($first['zones'][1])->toBe($lineup[1]->id)
        ->and($first['zones'][6])->toBe($defence->id)
        ->and($first['zones'][3])->toBe($lineup[3]->id);

    // rally 2: they serve, still rotation 1 -> reception libero
    expect($second['rot'])->toBe(1)->and($second['zones'][6])->toBe($reception->id);

    // rally 3: after the side-out we rotate: position II serves from zone 1
    expect($third)->toMatchArray(['rot' => 2, 'serve' => 'us', 'us' => 1, 'them' => 1])
        ->and($third['zones'][1])->toBe($lineup[2]->id)
        ->and($data['players'][$defence->id]['libero'])->toBeTrue();
});

it('gives the rotations to the 2D court and saves the liberos with the lineup', function () {
    ['club' => $club, 'game' => $game, 'set' => $set, 'lineup' => $lineup, 'reception' => $reception] = gameWithRotation();

    $component = Livewire::actingAs(member($club, Role::Trainer))->test(GameShow::class, ['game' => $game]);
    expect($component->instance()->rotations(app(RotationTimeline::class))['rallies'])->toHaveCount(3);

    $component->call('saveLineup', 1, collect($lineup)->map(fn ($p) => $p->id)->all(), 'us', $reception->id, null);
    expect($set->fresh()->libero_reception_id)->toBe($reception->id)
        ->and($set->fresh()->libero_defense_id)->toBeNull();
});

it('tells the analyzer the libero numbers', function () {
    ['club' => $club, 'game' => $game] = gameWithRotation();
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $this->getJson("/api/v1/games/{$game->id}")->assertOk()
        ->assertJsonPath('data.sets.0.libero_reception', 20)
        ->assertJsonPath('data.sets.0.libero_defense', 21);
});
