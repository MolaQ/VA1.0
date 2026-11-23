<?php

namespace App\Stats;

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\GameAction;
use App\Models\Player;

/**
 * Aggregated box-score line for one player (or the team total when $player is null).
 *
 * Formulas follow the common scouting conventions used in Polish/European volleyball:
 *  - Points  = aces + attack kills + block points
 *  - BP      = points scored in rallies where our team served (break points)
 *  - Balance = points - serve errors - reception errors - attack errors
 *  - Serve Eff%     = (# + / - =) / total
 *  - Reception Pos% = (# + +) / total, Perf% = # / total
 *  - Attack Kill%   = # / total, Eff% = (# - = - /) / total
 */
final class PlayerStatLine
{
    /** @var array<int, string> set number => rotation position ("1".."6") or "*" for a substitute */
    public array $sets = [];

    public int $points = 0;

    public int $breakPoints = 0;

    public int $serveTotal = 0;

    public int $serveErrors = 0;

    public int $serveAces = 0;

    public int $servePositive = 0;

    public int $receptionTotal = 0;

    public int $receptionErrors = 0;

    public int $receptionPositive = 0;

    public int $receptionPerfect = 0;

    public int $attackTotal = 0;

    public int $attackErrors = 0;

    public int $attackBlocked = 0;

    public int $attackKills = 0;

    public int $blockPoints = 0;

    public int $blockTouches = 0;

    public function __construct(public readonly ?Player $player = null) {}

    public function add(GameAction $action, bool $breakPointRally = false): void
    {
        $grade = $action->grade;

        switch ($action->skill) {
            case Skill::Serve:
                $this->serveTotal++;
                match ($grade) {
                    Grade::Perfect => $this->serveAces++,
                    Grade::Error => $this->serveErrors++,
                    Grade::Slash => $this->servePositive++,
                    default => null,
                };
                break;

            case Skill::Reception:
                $this->receptionTotal++;
                match ($grade) {
                    Grade::Perfect => [$this->receptionPerfect++, $this->receptionPositive++],
                    Grade::Positive => $this->receptionPositive++,
                    Grade::Error => $this->receptionErrors++,
                    default => null,
                };
                break;

            case Skill::Attack:
                $this->attackTotal++;
                match ($grade) {
                    Grade::Perfect => $this->attackKills++,
                    Grade::Error => $this->attackErrors++,
                    Grade::Slash => $this->attackBlocked++,
                    default => null,
                };
                break;

            case Skill::Block:
                match ($grade) {
                    Grade::Perfect => $this->blockPoints++,
                    Grade::Error => null,
                    default => $this->blockTouches++,
                };
                break;

            default:
                break;
        }

        if ($this->isPoint($action)) {
            $this->points++;

            if ($breakPointRally) {
                $this->breakPoints++;
            }
        }
    }

    public function isPoint(GameAction $action): bool
    {
        return $action->grade === Grade::Perfect
            && in_array($action->skill, [Skill::Serve, Skill::Attack, Skill::Block], true);
    }

    public function merge(self $other): void
    {
        foreach (get_object_vars($other) as $key => $value) {
            if (is_int($value)) {
                $this->{$key} += $value;
            }
        }
    }

    public function balance(): int
    {
        return $this->points - $this->serveErrors - $this->receptionErrors - $this->attackErrors;
    }

    public function serveEfficiency(): ?float
    {
        return $this->ratio($this->serveAces + $this->servePositive - $this->serveErrors, $this->serveTotal);
    }

    public function receptionPositive(): ?float
    {
        return $this->ratio($this->receptionPositive, $this->receptionTotal);
    }

    public function receptionPerfect(): ?float
    {
        return $this->ratio($this->receptionPerfect, $this->receptionTotal);
    }

    public function killPercentage(): ?float
    {
        return $this->ratio($this->attackKills, $this->attackTotal);
    }

    public function attackEfficiency(): ?float
    {
        return $this->ratio($this->attackKills - $this->attackErrors - $this->attackBlocked, $this->attackTotal);
    }

    public function hasPlayed(): bool
    {
        return $this->sets !== [] || $this->totalActions() > 0;
    }

    public function totalActions(): int
    {
        return $this->serveTotal + $this->receptionTotal + $this->attackTotal + $this->blockPoints + $this->blockTouches;
    }

    private function ratio(int $value, int $total): ?float
    {
        return $total > 0 ? $value / $total : null;
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '–' : round($value * 100).'%';
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player?->id,
            'points' => $this->points,
            'break_points' => $this->breakPoints,
            'balance' => $this->balance(),
            'serve_total' => $this->serveTotal,
            'serve_errors' => $this->serveErrors,
            'serve_aces' => $this->serveAces,
            'serve_eff' => self::percent($this->serveEfficiency()),
            'reception_total' => $this->receptionTotal,
            'reception_errors' => $this->receptionErrors,
            'reception_pos' => self::percent($this->receptionPositive()),
            'reception_perf' => self::percent($this->receptionPerfect()),
            'attack_total' => $this->attackTotal,
            'attack_errors' => $this->attackErrors,
            'attack_blocked' => $this->attackBlocked,
            'attack_kills' => $this->attackKills,
            'attack_kill_pct' => self::percent($this->killPercentage()),
            'attack_eff' => self::percent($this->attackEfficiency()),
            'block_points' => $this->blockPoints,
            'block_touches' => $this->blockTouches,
        ];
    }
}
