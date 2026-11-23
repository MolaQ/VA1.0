<?php

use App\Enums\ActionSource;
use App\Enums\Role;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Livewire\Games\GameCompare;
use App\Models\Game;
use App\Services\ActionIngestor;
use App\Stats\Report\MatchComparison;
use App\Stats\Report\MatchReportBuilder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(fn () => Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]));

/**
 * Two matches: the first with 1 kill out of 2 attacks, the second with 3 out of 3.
 *
 * @return array{0: array<string, mixed>, 1: Game, 2: Game}
 */
function twoMatches(): array
{
    $data = clubWithGame();
    $first = $data['game'];
    $first->update(['played_at' => now()->subWeek()]);
    $second = Game::factory()->for($data['club'])->for($data['team'])->create(['played_at' => now()]);

    app(ActionIngestor::class)->ingest($first, [
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
        ['set' => 1, 'rally' => 2, 'player_number' => 2, 'skill' => 'attack', 'grade' => '='],
    ], ActionSource::Manual);
    app(ActionIngestor::class)->ingest($second, [
        ['set' => 1, 'rally' => 1, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
        ['set' => 1, 'rally' => 2, 'player_number' => 2, 'skill' => 'attack', 'grade' => '#'],
        ['set' => 2, 'rally' => 1, 'player_number' => 13, 'skill' => 'attack', 'grade' => '#'],
    ], ActionSource::Manual);

    return [$data, $first->fresh(), $second->fresh()];
}

it('compares matches and adds them up from the counts', function () {
    [$data, $first, $second] = twoMatches();

    $c = new MatchComparison(collect([$first, $second]), app(MatchReportBuilder::class));

    expect($c->matches[$first->id]->kpis()['attack_success'])->toBe(0.5)
        ->and($c->matches[$second->id]->kpis()['attack_success'])->toBe(1.0)
        ->and($c->total->kpis()['attack_success'])->toBe(0.8)   // 4 of 5, not the mean of 50% and 100%
        ->and($c->total->matches)->toBe(2)
        ->and($c->trend('attack_success'))->toBe(0.5)
        ->and($c->trend('side_out'))->toBeNull();

    $opposite = $c->players[$data['players'][0]->id];
    expect($opposite['total']->skill(Skill::Attack)->total())->toBe(4)
        ->and($opposite['total']->setsPlayed())->toBe(2)
        ->and(array_keys($opposite['games']))->toBe([$first->id, $second->id])
        ->and($opposite['total']->value())->toBe(2.0);
});

it('shows the comparison page to staff and players', function () {
    [$data, $first, $second] = twoMatches();
    $this->withHeader('Accept-Language', 'pl-PL,pl;q=0.9');

    $this->actingAs(member($data['club'], Role::Trainer))->get('/games/compare')
        ->assertOk()->assertSee('Porównanie meczów')->assertSee('Drużyna mecz po meczu');
    $this->actingAs(member($data['club'], Role::Player))->get('/games/compare?m[]='.$first->id)->assertOk();
    $this->actingAs(member($data['club'], Role::Parent))->get('/games/compare')->assertForbidden();
});

it('picks the latest matches by default and toggles the selection', function () {
    [$data, $first, $second] = twoMatches();
    $other = clubWithGame()['game']; // another club's match can't be added

    Livewire::actingAs(member($data['club'], Role::Trainer))
        ->test(GameCompare::class)
        ->assertSet('selected', [$second->id, $first->id])
        ->call('toggle', $first->id)
        ->assertSet('selected', [$second->id])
        ->call('toggle', $other->id)
        ->assertSet('selected', [$second->id])
        ->call('toggle', $first->id)
        ->assertSet('selected', [$second->id, $first->id]);
});
