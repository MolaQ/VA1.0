<?php

use App\Enums\Grade;
use App\Enums\Skill;
use App\Stats\Report\PlayerValue;
use App\Stats\Report\SkillLine;

/**
 * @param  array<string, int>  $grades
 */
function skillLine(Skill $skill, array $grades): SkillLine
{
    $line = new SkillLine($skill);

    foreach ($grades as $grade => $n) {
        $line->addGrade(Grade::from($grade), $n);
    }

    return $line;
}

it('rates attack success and efficiency', function () {
    // 10 attacks: 5 kills, 1 error, 1 blocked => success 50%, efficiency 30%
    $line = skillLine(Skill::Attack, ['#' => 5, '+' => 2, '=' => 1, '/' => 1, '!' => 1]);

    expect($line->total())->toBe(10)
        ->and($line->success())->toBe(0.5)
        ->and($line->efficiency())->toEqualWithDelta(0.3, 1e-9)
        ->and($line->points())->toBe(5)
        ->and($line->pointsLost())->toBe(2);
});

it('rates reception: positive share and efficiency without overpasses', function () {
    // 10 receptions: 3 perfect, 3 positive, 1 overpass, 1 error
    $line = skillLine(Skill::Reception, ['#' => 3, '+' => 3, '!' => 1, '-' => 1, '/' => 1, '=' => 1]);

    expect($line->success())->toBe(0.6)
        ->and($line->perfect())->toBe(0.3)
        ->and($line->efficiency())->toEqualWithDelta(0.4, 1e-9)
        ->and($line->points())->toBe(0);
});

it('rates serve with aces and overpasses as good outcomes', function () {
    $line = skillLine(Skill::Serve, ['#' => 2, '/' => 1, '!' => 5, '=' => 2]);

    expect($line->success())->toBe(0.2)
        ->and($line->efficiency())->toEqualWithDelta(0.1, 1e-9)
        ->and($line->errorRate())->toBe(0.2);
});

it('rates setting: poor sets count against efficiency', function () {
    $line = skillLine(Skill::Set, ['#' => 4, '+' => 2, '!' => 2, '-' => 1, '=' => 1]);

    expect($line->success())->toBe(0.6)
        ->and($line->efficiency())->toEqualWithDelta(0.4, 1e-9);
});

it('returns no ratio without touches', function () {
    expect((new SkillLine(Skill::Block))->success())->toBeNull()
        ->and((new SkillLine(Skill::Block))->efficiency())->toBeNull();
});

it('values a point as +1 and an error as -1', function () {
    expect(PlayerValue::of(skillLine(Skill::Attack, ['#' => 3, '=' => 1, '/' => 1])))->toBe(1.0)
        ->and(PlayerValue::of(skillLine(Skill::Reception, ['#' => 1, '-' => 1])))->toBe(0.3)
        ->and(PlayerValue::weight(Skill::Serve, '#'))->toBe(1.0);
});
