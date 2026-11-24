<?php

use App\Enums\AnalysisStatus;
use App\Enums\Grade;
use App\Enums\Role;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Jobs\RequestVideoAnalysis;
use App\Livewire\Club\Integrations;
use App\Livewire\Games\GameShow;
use App\Models\Club;
use App\Models\SetLineup;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

it('lets the AI pipeline push actions idempotently and broadcasts them', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $payload = ['actions' => [
        ['external_id' => 'det-1', 'set' => 1, 'rally' => 3, 'player_number' => 13, 'skill' => 'attack', 'grade' => '#', 'video_time' => 812.4, 'confidence' => 0.93],
        ['external_id' => 'det-2', 'set' => 1, 'rally' => 4, 'player_number' => 2, 'skill' => 'serve', 'grade' => '='],
    ]];

    $this->postJson("/api/v1/games/{$game->id}/actions", $payload)
        ->assertCreated()
        ->assertJson(['created' => 2, 'updated' => 0]);

    $payload['actions'][0]['grade'] = '+';

    $this->postJson("/api/v1/games/{$game->id}/actions", $payload)
        ->assertOk()
        ->assertJson(['created' => 0, 'updated' => 2]);

    expect($game->actions()->count())->toBe(2)
        ->and($game->actions()->where('external_id', 'det-1')->first()->grade)->toBe(Grade::Positive)
        ->and($game->sets()->count())->toBe(1);

    Event::assertDispatched(GameActionsRecorded::class, 2);
});

it('adds unknown jersey numbers to the match team as #number', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Trainer), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [
        ['player_number' => 47, 'skill' => 'attack', 'grade' => '#'],
        ['player_number' => 47, 'skill' => 'serve', 'grade' => '='],
    ]])->assertCreated()->assertJson(['created' => 2, 'skipped' => []]);

    $player = $club->players()->where('number', 47)->sole();

    expect($player->team_id)->toBe($game->team_id)
        ->and($player->full_name)->toBe('#47')
        ->and($player->actions()->count())->toBe(2);

    $player->update(['nickname' => 'Kangur']);
    expect($player->fresh()->full_name)->toBe('Kangur');

    $player->update(['first_name' => 'Jan', 'last_name' => 'Nowak']);
    expect($player->fresh()->full_name)->toBe('Jan Nowak');
});

it('reports unknown player ids as skipped', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Trainer), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [
        ['player_id' => 999999, 'skill' => 'attack', 'grade' => '#'],
    ]])->assertOk()->assertJsonPath('skipped.0.index', 0);
});

it('validates skills and grades', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [
        ['skill' => 'smash', 'grade' => 'A'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0.skill', 'actions.0.grade']);
});

it('rejects tokens without the analysis ability', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['read']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [['skill' => 'serve', 'grade' => '#']]])
        ->assertForbidden();
});

it('isolates clubs: a token of another club cannot write', function () {
    ['game' => $game] = clubWithGame();
    Sanctum::actingAs(member(Club::factory()->create(), Role::Admin), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [['skill' => 'serve', 'grade' => '#']]])
        ->assertForbidden();
    $this->getJson("/api/v1/games/{$game->id}")->assertForbidden();
});

it('does not let players or parents write', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Player), ['analysis:write']);

    $this->postJson("/api/v1/games/{$game->id}/actions", ['actions' => [['skill' => 'serve', 'grade' => '#']]])
        ->assertForbidden();
});

it('updates analysis progress and set scores', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Admin), ['analysis:write']);

    $this->patchJson("/api/v1/games/{$game->id}/analysis", ['status' => 'processing', 'progress' => 40])
        ->assertOk()->assertJson(['status' => 'processing', 'progress' => 40]);

    $this->putJson("/api/v1/games/{$game->id}/sets/1", [
        'score_us' => 25, 'score_them' => 18, 'video_start' => 120,
        'lineup' => [['player_number' => 2, 'rotation_position' => 1], ['player_number' => 26]],
    ])->assertOk();

    $this->patchJson("/api/v1/games/{$game->id}/analysis", ['status' => 'completed'])->assertOk();

    $game->refresh();
    expect($game->analysis_status)->toBe(AnalysisStatus::Completed)
        ->and($game->analysis_progress)->toBe(100)
        ->and($game->result())->toBe('1:0')
        ->and($game->sets()->first()->lineups()->where('player_id', $players[2]->id)->value('is_substitute'))->toBeTruthy();

    Event::assertDispatched(GameAnalysisUpdated::class);
});

it('returns the roster so the analyzer can map jersey numbers', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    Sanctum::actingAs(member($club, Role::Trainer), ['analysis:write']);

    $this->getJson("/api/v1/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.youtube_id', 'dQw4w9WgXcQ')
        ->assertJsonCount(3, 'data.roster');
});

it('saves the starting rotation and gives it to the analyzer', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();
    $admin = member($club, Role::Admin);

    Livewire\Livewire::actingAs($admin)
        ->test(GameShow::class, ['game' => $game])
        ->call('saveLineup', 2, ['1' => $players[1]->id, '2' => $players[0]->id, '3' => ''], 'them');

    $set = $game->sets()->where('number', 2)->first();
    expect($set->we_serve_first)->toBeFalse()
        ->and($set->lineups()->count())->toBe(2);

    Sanctum::actingAs($admin, ['analysis:write']);
    $this->getJson("/api/v1/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('data.sets.0.we_serve_first', false)
        ->assertJsonPath('data.sets.0.lineup.0.rotation_position', 1)
        ->assertJsonPath('data.sets.0.lineup.0.number', 13)
        ->assertJsonPath('data.sets.0.lineup.1.number', 2);
});

it('rejects a player in two rotation positions', function () {
    ['club' => $club, 'game' => $game, 'players' => $players] = clubWithGame();

    Livewire\Livewire::actingAs(member($club, Role::Admin))
        ->test(GameShow::class, ['game' => $game])
        ->call('saveLineup', 1, ['1' => $players[0]->id, '2' => $players[0]->id]);

    expect(SetLineup::count())->toBe(0);
});

it('lets the club agent claim queued matches one at a time', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $other = clubWithGame();
    $game->forceFill(['analysis_status' => AnalysisStatus::Queued, 'analysis_updated_at' => now()])->save();
    $other['game']->forceFill(['analysis_status' => AnalysisStatus::Queued, 'analysis_updated_at' => now()])->save();

    $admin = member($club, Role::Admin);
    Sanctum::actingAs($admin, ['analysis:write', 'club:'.$club->id]);

    $this->postJson('/api/v1/agent/claim')
        ->assertOk()
        ->assertJsonPath('data.id', $game->id)
        ->assertJsonPath('data.youtube_id', 'dQw4w9WgXcQ');

    expect($game->fresh()->analysis_status)->toBe(AnalysisStatus::Processing);

    // nothing else for this club (the other club's match is not ours)
    $this->postJson('/api/v1/agent/claim')->assertNoContent();

    // an agent that died: the match goes back to the queue after a while
    $game->forceFill(['analysis_updated_at' => now()->subHour()])->save();
    $this->postJson('/api/v1/agent/claim')->assertOk()->assertJsonPath('data.id', $game->id);
});

it('queues matches for the agent when no analyzer url is configured', function () {
    ['game' => $game] = clubWithGame();
    config(['services.analyzer.url' => null]);

    (new RequestVideoAnalysis($game))->handle();

    expect($game->fresh()->analysis_status)->toBe(AnalysisStatus::Queued);
});

it('shows running analyses with an estimate on the integrations page', function () {
    ['club' => $club, 'game' => $game] = clubWithGame();
    $game->forceFill([
        'analysis_status' => AnalysisStatus::Processing,
        'analysis_progress' => 25,
        'analysis_message' => 'Analiza obrazu: 30 z 120 min',
        'analysis_started_at' => now()->subMinutes(10),
        'analysis_updated_at' => now(),
    ])->save();

    expect($game->fresh()->analysisSecondsLeft())->toBeBetween(1790, 1810);

    Livewire\Livewire::actingAs(member($club, Role::Admin))
        ->test(Integrations::class)
        ->assertSee('Analiza obrazu: 30 z 120 min')
        ->assertSee('25%');
});
