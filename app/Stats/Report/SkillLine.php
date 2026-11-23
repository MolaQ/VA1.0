<?php

namespace App\Stats\Report;

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\GameAction;

/**
 * Grade distribution of one skill (for a player, a set, a zone, a phase...) and its ratios.
 *
 * Two numbers per skill, as used by Polish/European scouts:
 *  - skuteczność (success): share of the best outcome (ace, kill, block point, perfect pass/set)
 *  - efektywność (efficiency): (good outcomes − outcomes that cost the point) / total
 *
 *  Skill      | success          | efficiency
 *  -----------+------------------+-------------------------------------
 *  serve      | # / total        | (# + / − =) / total
 *  reception  | (# + +) / total  | (# + + − / − =) / total
 *  attack     | # / total        | (# − / − =) / total
 *  block      | # / total        | (# + + − / − =) / total
 *  dig        | (# + +) / total  | (# + + − =) / total
 *  set        | (# + +) / total  | (# + + − − − =) / total
 *  freeball   | (# + +) / total  | (# + + − =) / total
 */
final class SkillLine
{
    /** @var array<string, int> grade symbol => count */
    public array $grades = ['#' => 0, '+' => 0, '!' => 0, '-' => 0, '/' => 0, '=' => 0];

    public function __construct(public readonly Skill $skill) {}

    public function add(GameAction $action): void
    {
        $this->grades[$action->grade->value]++;
    }

    public function addGrade(Grade $grade, int $count = 1): void
    {
        $this->grades[$grade->value] += $count;
    }

    public function merge(self $other): void
    {
        foreach ($other->grades as $g => $n) {
            $this->grades[$g] += $n;
        }
    }

    public function total(): int
    {
        return array_sum($this->grades);
    }

    public function count(string $grade): int
    {
        return $this->grades[$grade] ?? 0;
    }

    /** Best outcomes: aces, kills, block points (#) or good passes/sets (# and +). */
    public function successCount(): int
    {
        return match ($this->skill) {
            Skill::Serve, Skill::Attack, Skill::Block => $this->grades['#'],
            default => $this->grades['#'] + $this->grades['+'],
        };
    }

    /** Outcomes that directly cost us the rally (or set up the opponent). */
    public function negativeCount(): int
    {
        return match ($this->skill) {
            Skill::Serve => $this->grades['='],
            Skill::Reception, Skill::Attack, Skill::Block => $this->grades['='] + $this->grades['/'],
            Skill::Set => $this->grades['='] + $this->grades['-'],
            default => $this->grades['='],
        };
    }

    /** Points scored directly with this skill. */
    public function points(): int
    {
        return in_array($this->skill, [Skill::Serve, Skill::Attack, Skill::Block], true) ? $this->grades['#'] : 0;
    }

    /** Rallies lost directly by this skill. */
    public function pointsLost(): int
    {
        return match ($this->skill) {
            Skill::Attack, Skill::Block => $this->grades['='] + $this->grades['/'],
            default => $this->grades['='],
        };
    }

    public function errors(): int
    {
        return $this->grades['='];
    }

    public function success(): ?float
    {
        return $this->ratio($this->successCount());
    }

    public function efficiency(): ?float
    {
        $good = match ($this->skill) {
            Skill::Serve => $this->grades['#'] + $this->grades['/'],
            default => $this->successCount(),
        };

        return $this->ratio($good - $this->negativeCount());
    }

    public function perfect(): ?float
    {
        return $this->ratio($this->grades['#']);
    }

    public function errorRate(): ?float
    {
        return $this->ratio($this->grades['=']);
    }

    public function share(string $grade): ?float
    {
        return $this->ratio($this->count($grade));
    }

    private function ratio(int $value): ?float
    {
        $total = $this->total();

        return $total > 0 ? $value / $total : null;
    }
}
