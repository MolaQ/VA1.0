<?php

namespace App\Stats\Report;

use App\Enums\Skill;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Everything the match report shows, computed once from the actions (manual or AI).
 */
final class MatchReport
{
    public const SKILLS = [Skill::Serve, Skill::Reception, Skill::Attack, Skill::Block, Skill::Dig, Skill::Set, Skill::Freeball];

    /** Reception quality buckets used to split side-out and setting distribution. */
    public const RECEPTION_BUCKETS = ['perfect', 'positive', 'poor', 'transition'];

    /** Attack zones in court order: front row 4 3 2, back row 5 6 1. */
    public const ATTACK_ZONES = [4, 3, 2, 5, 6, 1];

    /** @var array<string, SkillLine> team totals per skill */
    public array $team = [];

    /** @var array<int, array<string, SkillLine>> set number => skill => line */
    public array $bySet = [];

    /** @var array<int, array{so: Rate, bp: Rate, us: int, them: int}> set number => phase rates and score */
    public array $setPhases = [];

    /** @var Collection<int, PlayerReport> */
    public Collection $players;

    /** @var Collection<int, Rally> */
    public Collection $rallies;

    public Rate $sideOut;

    public Rate $breakPoint;

    /** @var array<int, array{so: Rate, bp: Rate, us: int, them: int, server: ?Player}> rotation 1..6 */
    public array $rotations = [];

    /** @var array<string, array{so: Rate, attack: SkillLine}> reception grade => side-out and first attack */
    public array $receptionToSideOut = [];

    /** @var array<string, Rate> serve grade => break-point rate */
    public array $serveToBreak = [];

    /** @var array<string, SkillLine> sideout | transition */
    public array $attackByPhase = [];

    /** @var array<int|string, SkillLine> zone (or "none") => attack line */
    public array $attackByZone = [];

    /** @var array<int, array<int, int>> attack from zone => landing zone => count */
    public array $attackDirections = [];

    /** @var array<int, int> landing zone of our serves => count */
    public array $serveTargets = [];

    /** @var array<string, array<int|string, int>> reception bucket => attack zone => count */
    public array $distributionByZone = [];

    /** @var array<string, array<int, int>> reception bucket => attacker id => count */
    public array $distributionByAttacker = [];

    /** @var array<string, array<int|string, SkillLine>> reception bucket => zone => attack outcome */
    public array $distributionOutcome = [];

    /** @var array<string, SkillLine> set grade => attack after that set */
    public array $attackAfterSet = [];

    /** @var array<int, array{player: ?Player, set: SkillLine, attack: SkillLine}> setter id => sets and attacks after them */
    public array $setters = [];

    /** @var array<string, int> how our points were won */
    public array $pointsWon = ['ace' => 0, 'attack' => 0, 'block' => 0, 'opponent_error' => 0];

    /** @var array<string, int> how our points were lost */
    public array $pointsLost = ['serve' => 0, 'reception' => 0, 'attack' => 0, 'blocked' => 0, 'other' => 0, 'opponent' => 0];

    /** @var array<string, int> action source => count */
    public array $sources = [];

    /** @var array<int|string, Player> */
    public array $playerIndex = [];

    /**
     * @param  array<int, int>  $setNumbers
     */
    public function __construct(public readonly Game $game, public readonly ?int $setNumber, public readonly array $setNumbers)
    {
        $this->sideOut = new Rate;
        $this->breakPoint = new Rate;
        $this->players = collect();
        $this->rallies = collect();
    }

    public function teamSkill(Skill $skill): SkillLine
    {
        return $this->team[$skill->value];
    }

    public function hasZones(): bool
    {
        return collect($this->attackByZone)->except('none')->contains(fn (SkillLine $l) => $l->total() > 0);
    }

    public function knownRallies(): int
    {
        return $this->rallies->filter(fn (Rally $r) => $r->winner !== null)->count();
    }

    public function hasRotations(): bool
    {
        return collect($this->rotations)->contains(fn (array $r) => $r['so']->total + $r['bp']->total > 0);
    }

    public function totalPoints(): int
    {
        return array_sum($this->pointsWon);
    }

    /**
     * Players sorted by usefulness (value), best first.
     *
     * @return Collection<int, PlayerReport>
     */
    public function ranking(): Collection
    {
        return $this->players->filter(fn (PlayerReport $p) => $p->touches() > 0)
            ->sortByDesc(fn (PlayerReport $p) => [$p->valuePerSet() ?? -INF, $p->value()])
            ->values();
    }

    public function player(int $id): ?Player
    {
        return $this->playerIndex[$id] ?? null;
    }

    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'perfect' => __('After perfect reception (#)'),
            'positive' => __('After positive reception (+)'),
            'poor' => __('After poor reception (! - /)'),
            default => __('Transition (counter-attack)'),
        };
    }
}
