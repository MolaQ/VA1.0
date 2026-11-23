<?php

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameShow;
use App\Services\ActionIngestor;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('leaves unsure AI actions to clarify', function () {
    ['game' => $game] = clubWithGame();

    $result = app(ActionIngestor::class)->ingest($game, [
        ['external_id' => 'a', 'set' => 1, 'skill' => 'serve', 'grade' => '!', 'confidence' => 0.9],                         // no player
        ['external_id' => 'b', 'set' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'confidence' => 0.4],  // low confidence
        ['external_id' => 'c', 'set' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'confidence' => 0.95], // sure
        ['external_id' => 'd', 'set' => 1, 'player_number' => 2, 'skill' => 'block', 'grade' => '+', 'needs_review' => true, 'review_note' => 'Dwóch blokujących'],
    ], ActionSource::Ai);

    expect($result->actions->pluck('needs_review', 'external_id')->all())->toBe(['a' => true, 'b' => true, 'c' => false, 'd' => true])
        ->and($result->actions->firstWhere('external_id', 'd')->reviewReason())->toBe('Dwóch blokujących');

    $manual = app(ActionIngestor::class)->ingest($game, [['set' => 1, 'skill' => 'serve', 'grade' => '!']], ActionSource::Manual);
    expect($manual->actions->first()->needs_review)->toBeFalse();
});

it('lets the analyst confirm and correct an action, and the AI does not undo it', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $action = app(ActionIngestor::class)->ingest($game, [
        ['external_id' => 'srv-1', 'set' => 1, 'rally' => 1, 'skill' => 'serve', 'grade' => '!', 'video_time' => 42],
    ], ActionSource::Ai)->actions->first();

    Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameShow::class, ['game' => $game])
        ->assertSee(__('Player not recognised'))
        ->call('resolveAction', $action->id, $players[1]->id, '#');

    $action->refresh();
    expect($action->player_id)->toBe($players[1]->id)
        ->and($action->grade)->toBe(Grade::Perfect)
        ->and($action->needs_review)->toBeFalse()
        ->and($action->reviewed_at)->not->toBeNull();

    // the analyzer re-sends its window of detections
    app(ActionIngestor::class)->ingest($game, [
        ['external_id' => 'srv-1', 'set' => 1, 'rally' => 1, 'skill' => 'serve', 'grade' => '!', 'video_time' => 42],
    ], ActionSource::Ai);

    expect($action->fresh()->player_id)->toBe($players[1]->id)
        ->and($action->fresh()->grade)->toBe(Grade::Perfect);
});

it('flags actions from manual coding and from the timeline', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();

    $component = Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameShow::class, ['game' => $game])
        ->call('record', $players[0]->id, 'attack', '+', 10.0, null, null, true)
        ->call('record', $players[0]->id, 'attack', '#', 20.0);

    [$unclear, $clear] = $game->actions()->orderBy('id')->get()->all();
    expect($unclear->needs_review)->toBeTrue()->and($clear->needs_review)->toBeFalse();

    $component->call('flagAction', $clear->id, '  Kto atakował?  ');
    expect($clear->fresh()->needs_review)->toBeTrue()
        ->and($clear->fresh()->review_note)->toBe('Kto atakował?');

    $component->call('resolveAction', $clear->id, 999999)->assertStatus(422);
});

it('does not let players resolve actions', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $action = app(ActionIngestor::class)->ingest($game, [['set' => 1, 'skill' => 'serve', 'grade' => '!']], ActionSource::Ai)->actions->first();

    Livewire::actingAs(member($club, Role::Player))
        ->test(GameShow::class, ['game' => $game])
        ->call('resolveAction', $action->id)
        ->assertForbidden();
});

it('picks clips by player, skill, grade and zone', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 10, 'zone_from' => 4],
        ['set' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '=', 'video_time' => 20, 'zone_from' => 2],
        ['set' => 1, 'player_number' => 13, 'skill' => 'attack', 'grade' => '#', 'video_time' => 30, 'zone_from' => 4],
        ['set' => 1, 'skill' => 'serve', 'grade' => '!', 'video_time' => 40, 'zone_to' => 5],
    ], ActionSource::Manual);

    $show = Livewire::actingAs(member($club, Role::Player))->test(GameShow::class, ['game' => $game])->instance();

    expect($show->clips($players[0]->id, 'attack', 'all', 4))->toHaveCount(1)
        ->and($show->clips(null, 'attack', 'all', 4))->toHaveCount(2)
        ->and($show->clips(null, 'attack', '#'))->toHaveCount(2)
        ->and($show->clips(null, 'serve', 'all', 5)[0]['t'])->toBe(40.0)
        ->and($show->clips(null, 'serve', 'all', 6))->toBeEmpty();
});
