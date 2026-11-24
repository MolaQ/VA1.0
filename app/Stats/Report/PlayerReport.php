<?php

namespace App\Stats\Report;

use App\Enums\Skill;
use App\Models\Player;

/**
 * One player's line in the match report: every skill with its efficiency and success,
 * points won and lost, and the usefulness value.
 */
final class PlayerReport
{
    /** @var array<string, SkillLine> skill value => line */
    public array $skills = [];

    /** @var array<int, true> set numbers played */
    public array $sets = [];

    public function __construct(public readonly ?Player $player = null)
    {
        foreach (MatchReport::SKILLS as $skill) {
            $this->skills[$skill->value] = new SkillLine($skill);
        }
    }

    public function skill(Skill $skill): SkillLine
    {
        return $this->skills[$skill->value];
    }

    public function points(): int
    {
        return array_sum(array_map(fn (SkillLine $l) => $l->points(), $this->skills));
    }

    public function pointsLost(): int
    {
        return array_sum(array_map(fn (SkillLine $l) => $l->pointsLost(), $this->skills));
    }

    public function plusMinus(): int
    {
        return $this->points() - $this->pointsLost();
    }

    public function touches(): int
    {
        return array_sum(array_map(fn (SkillLine $l) => $l->total(), $this->skills));
    }

    public function value(): float
    {
        return PlayerValue::total($this->skills);
    }

    public function setsPlayed(): int
    {
        return count($this->sets);
    }

    public function valuePerSet(): ?float
    {
        return $this->setsPlayed() > 0 ? round($this->value() / $this->setsPlayed(), 2) : null;
    }

    /** Value per touch: how "clean" a player's touches are, independent of volume. */
    public function valuePerTouch(): ?float
    {
        return $this->touches() > 0 ? round($this->value() / $this->touches(), 3) : null;
    }
}
