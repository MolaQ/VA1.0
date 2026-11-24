<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameShow;
use App\Models\GameRally;
use App\Services\ActionIngestor;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('moves every time of the match after the warm-up was cut from the recording', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $set = $game->sets()->create(['number' => 1, 'video_start' => 700]);
    GameRally::create(['game_id' => $game->id, 'game_set_id' => $set->id, 'number' => 1, 'winner' => 'us', 'video_time' => 750]);

    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'serve', 'grade' => '!', 'video_time' => 100],   // in the warm-up
        ['set' => 1, 'rally' => 1, 'player_number' => 13, 'skill' => 'attack', 'grade' => '#', 'video_time' => 745.5],
        ['set' => 1, 'rally' => 2, 'player_number' => 26, 'skill' => 'dig', 'grade' => '+'],                        // no time
    ], ActionSource::Manual);

    foreach ([100, 600, 700, 701] as $t) {
        $game->frames()->create(['t' => $t, 'source' => 'ai', 'players' => []]);
    }

    Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameShow::class, ['game' => $game])
        ->call('shiftVideoTimes', -600)
        ->assertHasNoErrors();

    expect($game->actions()->orderBy('id')->pluck('video_time')->all())->toBe([null, 145.5, null])
        ->and((float) $set->fresh()->video_start)->toBe(100.0)
        ->and((float) GameRally::first()->video_time)->toBe(150.0)
        ->and($game->frames()->orderBy('t')->pluck('t')->map(fn ($t) => (float) $t)->all())->toBe([0.0, 100.0, 101.0]);

    // something added at the start: times move later
    Livewire::actingAs(member($club, Role::Admin))
        ->test(GameShow::class, ['game' => $game])
        ->call('shiftVideoTimes', 4.5);

    expect((float) $game->actions()->whereNotNull('video_time')->value('video_time'))->toBe(150.0);
});

it('lets only those who edit the match move its times', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();

    Livewire::actingAs(member($club, Role::Player))
        ->test(GameShow::class, ['game' => $game])
        ->call('shiftVideoTimes', -60)
        ->assertForbidden();
});
