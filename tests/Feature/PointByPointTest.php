<?php

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Role;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameRallies;
use App\Models\GameRally;
use App\Services\ActionIngestor;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

/**
 * Set 1 (2:1), the opponent serves first.
 *  r1 they serve: #26 reception +, #2 attack #   => 1:0
 *  r2 we serve:   #13 ace                        => 2:0
 *  r3 explicit: opponent wins, no touches        => 2:1
 * Plus an AI touch the analyzer could not place: no rally, no time, no player.
 */
function pointByPointGame(): array
{
    $data = clubWithGame();
    $game = $data['game'];
    $game->sets()->create(['number' => 1, 'score_us' => 2, 'score_them' => 1, 'we_serve_first' => false]);

    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 14],
        ['set' => 1, 'rally' => 1, 'player_number' => 26, 'skill' => 'reception', 'grade' => '+', 'video_time' => 11],
        ['set' => 1, 'rally' => 2, 'player_number' => 13, 'skill' => 'serve', 'grade' => '#', 'video_time' => 40],
    ], ActionSource::Manual);
    GameRally::create(['game_id' => $game->id, 'game_set_id' => $game->sets()->first()->id, 'number' => 3, 'winner' => 'them']);

    $loose = app(ActionIngestor::class)->ingest($game, [
        ['external_id' => 'x1', 'skill' => 'dig', 'grade' => '!', 'confidence' => 0.9],
    ], ActionSource::Ai)->actions->first();

    return [...$data, 'loose' => $loose];
}

it('lists every rally of a set with the score, the server, the winner and the touches in order', function () {
    ['club' => $club, 'game' => $game] = pointByPointGame();

    $component = Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameRallies::class, ['game' => $game])
        ->assertSee(__('Opponent serve'))
        ->assertSee(__('Ace'))
        ->assertSee(__('Attack kill'))
        ->assertSee(__('Not in a rally'))
        ->assertSee(__('no time'));

    $rallies = $component->viewData('sets')->first()['rallies'];

    expect(collect($rallies)->map(fn ($r) => [$r['number'], $r['server'], $r['winner'], $r['before'], $r['after']])->all())->toBe([
        [1, 'them', 'us', [0, 0], [1, 0]],
        [2, 'us', 'us', [1, 0], [2, 0]],
        [3, 'us', 'them', [2, 0], [2, 1]],
    ])
        // reception before the attack: playing order, not the order of entry
        ->and($rallies[0]['actions']->map(fn ($a) => $a->skill)->all())->toBe([Skill::Reception, Skill::Attack])
        ->and($rallies[0]['hasServe'])->toBeFalse()
        ->and($rallies[0]['start'])->toBe(11.0)
        ->and($rallies[2]['actions'])->toHaveCount(0)
        ->and($component->viewData('loose')->pluck('external_id')->all())->toBe(['x1']);
});

it('lets the coach correct a touch and put it into a rally', function () {
    ['club' => $club, 'game' => $game, 'players' => $players, 'loose' => $loose] = pointByPointGame();

    Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameRallies::class, ['game' => $game])
        ->call('updateAction', $loose->id, $players[2]->id, 'dig', '+', 1, 3, 77.4)
        ->assertDispatched('rallies-changed');

    $loose->refresh();
    expect($loose->player_id)->toBe($players[2]->id)
        ->and($loose->grade)->toBe(Grade::Positive)
        ->and($loose->rally)->toBe(3)
        ->and($loose->set->number)->toBe(1)
        ->and($loose->video_time)->toBe(77.4)
        ->and($loose->needs_review)->toBeFalse()
        ->and($loose->reviewed_at)->not->toBeNull();

    // the analyzer resends it: the correction stays
    app(ActionIngestor::class)->ingest($game, [['external_id' => 'x1', 'skill' => 'dig', 'grade' => '!']], ActionSource::Ai);
    expect($loose->fresh()->rally)->toBe(3)->and($loose->fresh()->grade)->toBe(Grade::Positive);
});

it('adds touches and rallies, and overrides who won', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = pointByPointGame();
    $set = $game->sets()->first();

    $component = Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameRallies::class, ['game' => $game])
        ->call('addAction', 1, 3, $players[1]->id, 'attack', '=', 61.0)
        ->call('addAction', 1, 3, null, 'dig', '-', null)
        ->call('setWinner', 1, 2, 'them')
        ->call('setServer', 1, 1, 'us')
        ->call('addRally', 1);

    expect($game->actions()->where('rally', 3)->count())->toBe(2)
        ->and($game->actions()->where('rally', 3)->whereNull('player_id')->first()->video_time)->toBeNull()
        ->and(GameRally::where('game_set_id', $set->id)->where('number', 2)->value('winner'))->toBe('them')
        ->and(GameRally::where('game_set_id', $set->id)->where('number', 1)->value('server'))->toBe('us')
        ->and(GameRally::where('game_set_id', $set->id)->where('number', 4)->exists())->toBeTrue();

    // back to what the touches tell: our ace wins rally 2
    $component->call('setWinner', 1, 2, null);
    $rallies = $component->viewData('sets')->first()['rallies'];

    expect($rallies[1]['winner'])->toBe('us')
        ->and(collect($rallies)->pluck('number')->all())->toBe([1, 2, 3, 4]);

    $component->call('addAction', 1, 3, 999999, 'dig', '-')->assertStatus(422);
});

it('lets players watch the rallies but not edit them', function () {
    ['club' => $club, 'game' => $game, 'loose' => $loose] = pointByPointGame();

    Livewire::actingAs(member($club, Role::Player))
        ->test(GameRallies::class, ['game' => $game])
        ->assertSee(__('Opponent serve'))
        ->assertDontSee(__('Add touch'))
        ->call('updateAction', $loose->id, null, 'dig', '#')
        ->assertForbidden();
});

it('shows the point-by-point tab on the match page', function () {
    ['club' => $club, 'game' => $game] = pointByPointGame();

    $this->actingAs(member($club, Role::Trainer))
        ->get(route('games.show', $game))
        ->assertOk()
        ->assertSee(__('Point by point'))
        ->assertSeeLivewire(GameRallies::class);
});
