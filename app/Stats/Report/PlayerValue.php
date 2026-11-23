<?php

namespace App\Stats\Report;

use App\Enums\Skill;

/**
 * Player usefulness ("użyteczność"): how much a player's touches helped the team win rallies.
 *
 * Every coded touch has a value: a direct point is +1, a direct error −1, and the in-between
 * grades weigh how much they helped (a perfect pass makes a side-out much more likely) or
 * hurt (a blocked attack is as bad as an error). The sum is the player's value; divided by
 * the sets played it compares players with different playing time.
 */
final class PlayerValue
{
    /** @var array<string, array<string, float>> skill => grade => value */
    public const WEIGHTS = [
        'serve' => ['#' => 1.0, '/' => 0.6, '+' => 0.3, '!' => 0.0, '-' => -0.2, '=' => -1.0],
        'reception' => ['#' => 0.6, '+' => 0.4, '!' => 0.0, '-' => -0.3, '/' => -0.6, '=' => -1.0],
        'attack' => ['#' => 1.0, '+' => 0.3, '!' => 0.0, '-' => -0.3, '/' => -1.0, '=' => -1.0],
        'block' => ['#' => 1.0, '+' => 0.5, '!' => 0.2, '-' => -0.1, '/' => -0.5, '=' => -1.0],
        'dig' => ['#' => 0.6, '+' => 0.4, '!' => 0.1, '-' => -0.1, '/' => -0.3, '=' => -0.5],
        'set' => ['#' => 0.4, '+' => 0.2, '!' => 0.0, '-' => -0.3, '/' => -0.5, '=' => -1.0],
        'freeball' => ['#' => 0.3, '+' => 0.2, '!' => 0.0, '-' => -0.2, '/' => -0.4, '=' => -1.0],
    ];

    public static function of(SkillLine $line): float
    {
        $weights = self::WEIGHTS[$line->skill->value];
        $sum = 0.0;

        foreach ($line->grades as $grade => $n) {
            $sum += ($weights[$grade] ?? 0.0) * $n;
        }

        return round($sum, 2);
    }

    /**
     * @param  array<string, SkillLine>  $skills
     */
    public static function total(array $skills): float
    {
        return round(array_sum(array_map(fn (SkillLine $l) => self::of($l), $skills)), 2);
    }

    public static function weight(Skill $skill, string $grade): float
    {
        return self::WEIGHTS[$skill->value][$grade] ?? 0.0;
    }
}
