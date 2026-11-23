<?php

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameShow;
use App\Services\ActionIngestor;
use App\Stats\PassQuality;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('takes player positions from the analyzer, idempotent by video time', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $frames = ['frames' => [
        ['t' => 10.0, 'players' => [['team' => 'us', 'k' => 1, 'x' => 4.5, 'y' => 3.2, 'f' => 0], ['team' => 'them', 'k' => 2, 'x' => 2, 'y' => 15, 'f' => 180]]],
        ['t' => 10.5, 'players' => [['team' => 'us', 'k' => 1, 'x' => 4.6, 'y' => 3.6, 'f' => 370]]],
    ]];

    $this->postJson("/api/v1/games/{$game->id}/frames", $frames)->assertOk()->assertJson(['saved' => 2]);
    $frames['frames'][1]['players'][0]['x'] = 5.0;
    $this->postJson("/api/v1/games/{$game->id}/frames", $frames)->assertOk();

    expect($game->frames()->count())->toBe(2)
        ->and($game->frames()->orderBy('t')->get()->last()->players[0])->toMatchArray(['x' => 5.0, 'f' => 10.0, 'n' => null]);

    $this->putJson("/api/v1/games/{$game->id}/sets/1", ['score_us' => 3, 'score_them' => 1, 'our_side' => 'far'])->assertOk();
    expect($game->sets()->first()->our_side)->toBe('far');

    $this->deleteJson("/api/v1/games/{$game->id}/frames")->assertOk()->assertJson(['deleted' => 2]);
});

it('rejects positions without the analysis ability', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['read']);

    $this->postJson("/api/v1/games/{$game->id}/frames", ['frames' => [['t' => 1, 'players' => []]]])->assertForbidden();
});

it('serves positions to the 2D court and lets the analyst name a track', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $game->frames()->create(['t' => 30, 'source' => ActionSource::Ai, 'players' => [['team' => 'us', 'n' => null, 'k' => 7, 'x' => 3, 'y' => 4, 'f' => 0]]]);
    $game->frames()->create(['t' => 400, 'source' => ActionSource::Ai, 'players' => []]);

    $component = Livewire::actingAs(member($club, Role::Trainer))->test(GameShow::class, ['game' => $game])
        ->assertSee(__('Court view'))
        ->call('labelTrack', 7, 13, 'us');

    $data = $component->instance()->frames(0, 60);
    expect($data['frames'])->toHaveCount(1)
        ->and($data['frames'][0]['p'][0]['k'])->toBe(7)
        ->and($data['labels'][7])->toBe(['team' => 'us', 'n' => 13]);

    $component->call('setOurSide', 2, 'far');
    expect($game->sets()->where('number', 2)->value('our_side'))->toBe('far');
});

it('does not let a player rename tracks', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();

    Livewire::actingAs(member($club, Role::Player))->test(GameShow::class, ['game' => $game])
        ->call('labelTrack', 1, 5, 'us')
        ->assertForbidden();
});

it('grades receptions by where the pass went', function () {
    expect(PassQuality::reception(6.2, 8.3))->toBe(Grade::Perfect)    // at the target
        ->and(PassQuality::reception(4.5, 7.0))->toBe(Grade::Positive)  // in front of the 3 m line
        ->and(PassQuality::reception(4.5, 4.0))->toBe(Grade::Neutral)   // behind the 3 m line
        ->and(PassQuality::reception(-1.0, 4.0))->toBe(Grade::Poor)     // off the court
        ->and(PassQuality::reception(5.0, 10.0))->toBe(Grade::Slash)    // over the net
        ->and(PassQuality::serveFromReception(Grade::Poor))->toBe(Grade::Positive)
        ->and(PassQuality::serveFromReception(Grade::Perfect))->toBe(Grade::Poor)
        ->and(PassQuality::serveFromReception(Grade::Error))->toBe(Grade::Perfect)
        ->and(PassQuality::serveZone(1.0, 17.0))->toBe(1)  // deep, on the receivers' right
        ->and(PassQuality::serveZone(4.5, 13.5))->toBe(8)
        ->and(PassQuality::serveZone(8.0, 10.0))->toBe(4);
});

it('stores the ball point of a reception and suggests its grade', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $action = app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'player_number' => 13, 'skill' => 'reception', 'grade' => '!', 'video_time' => 50],
    ], ActionSource::Manual)->actions->first();

    $component = Livewire::actingAs(member($club, Role::Trainer))->test(GameShow::class, ['game' => $game]);

    expect($component->instance()->ballActions(52))->toHaveCount(1);

    $component->instance()->setBallPoint($action->id, 'from', 1.5, 1.0);
    expect($component->instance()->setBallPoint($action->id, 'to', 6.1, 8.2))->toBe(['suggested' => '#', 'zone' => null]);

    $action->refresh();
    expect([$action->from_x, $action->from_y, $action->to_x, $action->to_y])->toBe([1.5, 1.0, 6.1, 8.2])
        ->and($action->zone_from)->toBe(5); // received deep on our left: zone 5
});
