<?php

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\GameAction;
use App\Stats\PlayerStatLine;

function scoutAction(Skill $skill, Grade $grade): GameAction
{
    return new GameAction(['skill' => $skill, 'grade' => $grade]);
}

/**
 * @param  array<string, int>  $grades
 */
function feed(PlayerStatLine $line, Skill $skill, array $grades): void
{
    foreach ($grades as $grade => $count) {
        for ($i = 0; $i < $count; $i++) {
            $line->add(scoutAction($skill, Grade::from($grade)));
        }
    }
}

it('computes serve efficiency like the reference box score', function () {
    // 7 serves, 5 errors, 0 aces => -71%
    $line = new PlayerStatLine;
    feed($line, Skill::Serve, ['=' => 5, '!' => 2]);

    expect(PlayerStatLine::percent($line->serveEfficiency()))->toBe('-71%');

    // 13 serves, 2 aces, 1 overpass, 3 errors => 0%
    $line = new PlayerStatLine;
    feed($line, Skill::Serve, ['#' => 2, '/' => 1, '=' => 3, '!' => 7]);

    expect(PlayerStatLine::percent($line->serveEfficiency()))->toBe('0%');
});

it('computes attack kill% and efficiency', function () {
    // 27 attacks, 10 kills, 3 errors, 2 blocked => Kill 37%, Eff 19%
    $line = new PlayerStatLine;
    feed($line, Skill::Attack, ['#' => 10, '=' => 3, '/' => 2, '+' => 12]);

    expect(PlayerStatLine::percent($line->killPercentage()))->toBe('37%')
        ->and(PlayerStatLine::percent($line->attackEfficiency()))->toBe('19%')
        ->and($line->attackBlocked)->toBe(2);
});

it('computes reception positive and perfect percentages', function () {
    // 16 receptions: 2 perfect, 8 positive => Pos 62%, Perf 12%
    $line = new PlayerStatLine;
    feed($line, Skill::Reception, ['#' => 2, '+' => 8, '!' => 6]);

    expect(PlayerStatLine::percent($line->receptionPositive()))->toBe('63%')
        ->and(PlayerStatLine::percent($line->receptionPerfect()))->toBe('13%');
});

it('counts points and balance', function () {
    $line = new PlayerStatLine;
    feed($line, Skill::Attack, ['#' => 10, '=' => 3]);
    feed($line, Skill::Block, ['#' => 2, '+' => 3]);
    feed($line, Skill::Serve, ['=' => 5]);
    feed($line, Skill::Reception, ['=' => 1]);

    // points 12, errors 5 + 1 + 3 => balance 3 (blocked attacks are not counted)
    expect($line->points)->toBe(12)
        ->and($line->balance())->toBe(3)
        ->and($line->blockTouches)->toBe(3);
});

it('shows a dash when there is nothing to divide', function () {
    expect(PlayerStatLine::percent((new PlayerStatLine)->killPercentage()))->toBe('–');
});
