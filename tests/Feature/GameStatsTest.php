<?php

use App\Enums\ActionSource;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Services\ActionIngestor;
use App\Stats\GameStatsCalculator;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('builds the box score from actions, with sets and break points', function () {
    ['game' => $game, 'players' => $players] = clubWithGame();

    $set = $game->sets()->create(['number' => 1, 'score_us' => 25, 'score_them' => 20]);
    $set->lineups()->create(['player_id' => $players[0]->id, 'rotation_position' => 4]);
    $set->lineups()->create(['player_id' => $players[2]->id, 'is_substitute' => true]);

    app(ActionIngestor::class)->ingest($game, [
        // rally 1: we serve (#13), #2 kills => break point
        ['set' => 1, 'rally' => 1, 'player_number' => 13, 'skill' => 'serve', 'grade' => '!', 'video_time' => 10],
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 15],
        // rally 2: we receive (#26), #2 kills => side-out point (no BP)
        ['set' => 1, 'rally' => 2, 'player_number' => 26, 'skill' => 'reception', 'grade' => '+', 'video_time' => 40],
        ['set' => 1, 'rally' => 2, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 44],
        ['set' => 1, 'rally' => 3, 'player_number' => 2, 'skill' => 'attack', 'grade' => '=', 'video_time' => 70],
    ], ActionSource::Ai);

    $stats = app(GameStatsCalculator::class)->forGame($game->fresh());
    $opposite = $stats->forPlayer($players[0]->id);

    expect($opposite->points)->toBe(2)
        ->and($opposite->breakPoints)->toBe(1)
        ->and($opposite->attackTotal)->toBe(3)
        ->and($opposite->balance())->toBe(1)
        ->and($opposite->sets)->toBe([1 => '4'])
        ->and($stats->forPlayer($players[2]->id)->sets)->toBe([1 => '*'])
        ->and($stats->forPlayer($players[2]->id)->receptionTotal)->toBe(1)
        ->and($stats->totals->points)->toBe(2);
});

it('limits lines to the given players', function () {
    ['game' => $game, 'players' => $players] = clubWithGame();

    $stats = app(GameStatsCalculator::class)->forGame($game, null, [$players[2]->id]);

    expect($stats->lines)->toHaveCount(1)
        ->and($stats->lines->first()->player->id)->toBe($players[2]->id);
});

it('filters by set', function () {
    ['game' => $game] = clubWithGame();

    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
        ['set' => 2, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
        ['set' => 2, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
    ], ActionSource::Manual);

    $calc = app(GameStatsCalculator::class);

    expect($calc->forGame($game->fresh(), 2)->totals->attackKills)->toBe(2)
        ->and($calc->forGame($game->fresh())->totals->attackKills)->toBe(3);
});
