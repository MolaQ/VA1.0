<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameShow;
use App\Models\GameRally;
use App\Models\Player;
use App\Services\ActionIngestor;
use App\Stats\Report\MatchReportBuilder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

/**
 * Set 1, opponent serves first, our rotation I #13, II #2, III #26.
 *
 *  r1 they serve: #26 reception #, #7 set +, #2 attack 4→5 #   => side-out won (rotate)
 *  r2 we serve:   #2 serve !, #13 attack =                    => break point lost
 *  r3 (no touches) explicit: opponent wins                    => side-out lost
 *  r4 they serve: #26 reception -, #13 attack from 2 blocked  => side-out lost
 *  r5 (no touches) explicit: we win (opponent error)          => side-out won (rotate)
 *  r6 we serve:   #13 ace                                     => break point won
 */
function scoutedGame(): array
{
    $data = clubWithGame();
    ['club' => $club, 'team' => $team, 'game' => $game, 'players' => $players] = $data;
    $setter = Player::factory()->for($club)->for($team)->create(['number' => 7, 'position' => 'setter']);

    $set = $game->sets()->create(['number' => 1, 'score_us' => 3, 'score_them' => 3, 'we_serve_first' => false]);
    $set->lineups()->create(['player_id' => $players[1]->id, 'rotation_position' => 1]); // #13
    $set->lineups()->create(['player_id' => $players[0]->id, 'rotation_position' => 2]); // #2
    $set->lineups()->create(['player_id' => $players[2]->id, 'rotation_position' => 3]); // #26

    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'rally' => 1, 'player_number' => 26, 'skill' => 'reception', 'grade' => '#', 'video_time' => 10],
        ['set' => 1, 'rally' => 1, 'player_number' => 7, 'skill' => 'set', 'grade' => '+', 'video_time' => 11],
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#', 'video_time' => 12, 'zone_from' => 4, 'zone_to' => 5],
        ['set' => 1, 'rally' => 2, 'player_number' => 2, 'skill' => 'serve', 'grade' => '!', 'video_time' => 30],
        ['set' => 1, 'rally' => 2, 'player_number' => 13, 'skill' => 'attack', 'grade' => '=', 'video_time' => 35],
        ['set' => 1, 'rally' => 4, 'player_number' => 26, 'skill' => 'reception', 'grade' => '-', 'video_time' => 70],
        ['set' => 1, 'rally' => 4, 'player_number' => 13, 'skill' => 'attack', 'grade' => '/', 'video_time' => 73, 'zone_from' => 2],
        ['set' => 1, 'rally' => 6, 'player_number' => 13, 'skill' => 'serve', 'grade' => '#', 'video_time' => 110, 'zone_to' => 1],
    ], ActionSource::Manual);

    GameRally::create(['game_id' => $game->id, 'game_set_id' => $set->id, 'number' => 3, 'winner' => 'them']);
    GameRally::create(['game_id' => $game->id, 'game_set_id' => $set->id, 'number' => 5, 'winner' => 'us']);

    return [...$data, 'setter' => $setter];
}

it('rebuilds rallies: server, winner, rotation and running score', function () {
    ['game' => $game] = scoutedGame();

    $rallies = app(MatchReportBuilder::class)->build($game->fresh())->rallies;

    expect($rallies->pluck('number')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($rallies->pluck('server')->all())->toBe(['them', 'us', 'them', 'them', 'them', 'us'])
        ->and($rallies->pluck('winner')->all())->toBe(['us', 'them', 'them', 'them', 'us', 'us'])
        ->and($rallies->pluck('rotation')->all())->toBe([1, 2, 2, 2, 2, 3])
        ->and($rallies->last()->scoreUs)->toBe(2)
        ->and($rallies->last()->scoreThem)->toBe(3);
});

it('computes side-out, break point and rotations', function () {
    ['game' => $game, 'players' => $players] = scoutedGame();

    $report = app(MatchReportBuilder::class)->build($game->fresh());

    expect([$report->sideOut->won, $report->sideOut->total])->toBe([2, 4])
        ->and([$report->breakPoint->won, $report->breakPoint->total])->toBe([1, 2])
        ->and([$report->rotations[1]['so']->won, $report->rotations[1]['so']->total])->toBe([1, 1])
        ->and([$report->rotations[2]['so']->won, $report->rotations[2]['so']->total])->toBe([1, 3])
        ->and([$report->rotations[2]['us'], $report->rotations[2]['them']])->toBe([1, 3])
        ->and([$report->rotations[3]['bp']->won, $report->rotations[3]['bp']->total])->toBe([1, 1])
        ->and($report->rotations[2]['server']->id)->toBe($players[0]->id)
        ->and([$report->setPhases[1]['us'], $report->setPhases[1]['them']])->toBe([3, 3]);
});

it('explains how points were won and lost', function () {
    ['game' => $game] = scoutedGame();

    $report = app(MatchReportBuilder::class)->build($game->fresh());

    expect($report->pointsWon)->toBe(['ace' => 1, 'attack' => 1, 'block' => 0, 'opponent_error' => 1])
        ->and($report->pointsLost)->toBe(['serve' => 0, 'reception' => 0, 'attack' => 1, 'blocked' => 1, 'other' => 0, 'opponent' => 1]);
});

it('links reception quality to side-out and splits attacks by phase', function () {
    ['game' => $game] = scoutedGame();

    $report = app(MatchReportBuilder::class)->build($game->fresh());

    expect($report->receptionToSideOut['#']['so']->ratio())->toBe(1.0)
        ->and($report->receptionToSideOut['#']['attack']->success())->toBe(1.0)
        ->and($report->receptionToSideOut['-']['so']->ratio())->toBe(0.0)
        ->and($report->attackByPhase['sideout']->total())->toBe(2)
        ->and($report->attackByPhase['transition']->total())->toBe(1)
        ->and($report->serveToBreak['#']->ratio())->toBe(1.0)
        ->and($report->serveToBreak['!']->ratio())->toBe(0.0);
});

it('reports setting distribution and quality', function () {
    ['game' => $game, 'setter' => $setter, 'players' => $players] = scoutedGame();

    $report = app(MatchReportBuilder::class)->build($game->fresh());

    expect($report->distributionByZone['perfect'])->toBe([4 => 1])
        ->and($report->distributionByZone['poor'])->toBe([2 => 1])
        ->and($report->distributionByZone['transition'])->toBe(['none' => 1])
        ->and($report->distributionByAttacker['perfect'])->toBe([$players[0]->id => 1])
        ->and($report->setters[$setter->id]['set']->total())->toBe(1)
        ->and($report->setters[$setter->id]['attack']->success())->toBe(1.0)
        ->and($report->attackAfterSet['+']->total())->toBe(1)
        ->and($report->attackDirections)->toBe([4 => [5 => 1]])
        ->and($report->serveTargets)->toBe([1 => 1])
        ->and($report->hasZones())->toBeTrue();
});

it('ranks player usefulness', function () {
    ['game' => $game, 'players' => $players] = scoutedGame();

    $report = app(MatchReportBuilder::class)->build($game->fresh());
    $byId = $report->players->keyBy(fn ($p) => $p->player->id);

    expect($byId[$players[0]->id]->value())->toBe(1.0)      // #2: kill
        ->and($byId[$players[1]->id]->value())->toBe(-1.0)  // #13: error, blocked, ace
        ->and($byId[$players[2]->id]->value())->toBe(0.3)   // #26: # and - reception
        ->and($byId[$players[1]->id]->plusMinus())->toBe(-1)
        ->and($byId[$players[1]->id]->setsPlayed())->toBe(1)
        ->and($report->ranking()->first()->player->id)->toBe($players[0]->id);
});

it('fills the gaps: the winner of a rally serves the next one', function () {
    ['game' => $game] = clubWithGame();
    $game->sets()->create(['number' => 1]);

    app(ActionIngestor::class)->ingest($game, [
        ['set' => 1, 'rally' => 1, 'player_number' => 26, 'skill' => 'reception', 'grade' => '+'],
        ['set' => 1, 'rally' => 2, 'player_number' => 13, 'skill' => 'serve', 'grade' => '!'],
    ], ActionSource::Ai);

    $rallies = app(MatchReportBuilder::class)->build($game->fresh())->rallies;

    expect($rallies[0]->winner)->toBe('us')
        ->and($rallies[0]->rotation)->toBeNull()
        ->and($rallies[1]->server)->toBe('us');
});

it('filters the report by set', function () {
    ['game' => $game] = scoutedGame();
    app(ActionIngestor::class)->ingest($game, [
        ['set' => 2, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
    ], ActionSource::Manual);

    $builder = app(MatchReportBuilder::class);

    expect($builder->build($game->fresh(), 2)->team['attack']->total())->toBe(1)
        ->and($builder->build($game->fresh(), 2)->sideOut->total)->toBe(0)
        ->and($builder->build($game->fresh())->team['attack']->total())->toBe(4)
        ->and($builder->build($game->fresh())->bySet[1]['attack']->total())->toBe(3);
});

it('shows the report to staff and players but not to parents', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = scoutedGame();

    $this->actingAs(member($club, Role::Trainer))->get("/games/{$game->id}/report")
        ->assertOk()->assertSee('Raport meczu')->assertSee('Użyteczność zawodników')->assertSee('Dystrybucja rozegrania');
    $this->actingAs(member($club, Role::Player))->get("/games/{$game->id}/report?set=1")->assertOk();

    $parent = member($club, Role::Parent);
    $parent->children()->attach($players[2]->id);
    $this->actingAs($parent->fresh())->get("/games/{$game->id}/report")->assertForbidden();
});

it('renders the report of a match without any data', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();

    $this->actingAs(member($club, Role::Admin))->get("/games/{$game->id}/report")->assertOk();
});

it('records zones and explicit rally points from manual coding', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();

    $component = Livewire::actingAs(member($club, Role::Trainer))
        ->test(GameShow::class, ['game' => $game])
        ->call('record', $players[0]->id, 'attack', '!', 12.5, 4, 6)
        ->call('point', 'them', 14.0)
        ->assertSet('rally', 2);

    $action = $game->actions()->first();
    $rally = $game->rallies()->first();

    expect([$action->zone_from, $action->zone_to])->toBe([4, 6])
        ->and($rally->number)->toBe(1)
        ->and($rally->winner)->toBe('them')
        ->and($rally->source)->toBe(ActionSource::Manual);

    $component->call('point', 'nobody')->assertStatus(422);
});

it('lets the analyzer push rally outcomes without overwriting a trainer', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $set = $game->sets()->create(['number' => 1]);
    GameRally::create(['game_id' => $game->id, 'game_set_id' => $set->id, 'number' => 2, 'winner' => 'us', 'source' => 'manual']);
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/rallies", ['rallies' => [
        ['set' => 1, 'rally' => 1, 'server' => 'us', 'winner' => 'them', 'video_time' => 12.3],
        ['set' => 1, 'rally' => 2, 'server' => 'them', 'winner' => 'them'],
        ['set' => 2, 'rally' => 1, 'server' => 'them', 'winner' => null],
    ]])->assertOk()->assertJson(['saved' => 2, 'kept' => 1]);

    expect($game->rallies()->count())->toBe(3)
        ->and($set->rallies()->where('number', 2)->value('winner'))->toBe('us')
        ->and($game->sets()->count())->toBe(2);

    // re-analysis wipes the AI outcomes only
    app(ActionIngestor::class)->purge($game, ActionSource::Ai);
    expect($game->rallies()->count())->toBe(1);

    $this->postJson("/api/v1/games/{$game->id}/rallies", ['rallies' => [['set' => 1, 'rally' => 1, 'winner' => 'draw']]])
        ->assertUnprocessable();
});

it('refuses rally outcomes without the analysis scope', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), []);

    $this->postJson("/api/v1/games/{$game->id}/rallies", ['rallies' => [['set' => 1, 'rally' => 1, 'winner' => 'us']]])
        ->assertForbidden();
});
