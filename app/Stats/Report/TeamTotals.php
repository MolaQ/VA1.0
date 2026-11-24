<?php

namespace App\Stats\Report;

use App\Enums\Skill;

/**
 * Team numbers of one match, or of several matches added up (for comparisons and season averages).
 * Ratios of a sum are computed from the summed counts, not by averaging percentages.
 */
final class TeamTotals
{
    /** @var array<string, SkillLine> */
    public array $skills = [];

    public Rate $sideOut;

    public Rate $breakPoint;

    /** @var array<string, int> */
    public array $pointsWon = [];

    /** @var array<string, int> */
    public array $pointsLost = [];

    public int $matches = 0;

    public int $setsWon = 0;

    public int $setsLost = 0;

    public function __construct()
    {
        foreach (MatchReport::SKILLS as $skill) {
            $this->skills[$skill->value] = new SkillLine($skill);
        }

        $this->sideOut = new Rate;
        $this->breakPoint = new Rate;
    }

    public static function fromReport(MatchReport $report): self
    {
        $t = new self;
        $t->add($report);

        return $t;
    }

    public function add(MatchReport $report): void
    {
        foreach ($report->team as $key => $line) {
            $this->skills[$key]->merge($line);
        }

        $this->sideOut->won += $report->sideOut->won;
        $this->sideOut->total += $report->sideOut->total;
        $this->breakPoint->won += $report->breakPoint->won;
        $this->breakPoint->total += $report->breakPoint->total;

        foreach ($report->pointsWon as $k => $n) {
            $this->pointsWon[$k] = ($this->pointsWon[$k] ?? 0) + $n;
        }

        foreach ($report->pointsLost as $k => $n) {
            $this->pointsLost[$k] = ($this->pointsLost[$k] ?? 0) + $n;
        }

        $this->matches++;
        $this->setsWon += $report->game->sets_won;
        $this->setsLost += $report->game->sets_lost;
    }

    public function skill(Skill $skill): SkillLine
    {
        return $this->skills[$skill->value];
    }

    /** Points given away by our own errors (serve, reception, attack, blocked, other). */
    public function errorsGiven(): int
    {
        return array_sum($this->pointsLost) - ($this->pointsLost['opponent'] ?? 0);
    }

    /**
     * The key numbers compared match by match: key => [value, "higher is better"].
     *
     * @return array<string, ?float>
     */
    public function kpis(): array
    {
        $per = fn (int $n): ?float => $this->matches > 0 ? $n / $this->matches : null;

        return [
            'side_out' => $this->sideOut->ratio(),
            'break_point' => $this->breakPoint->ratio(),
            'serve_success' => $this->skill(Skill::Serve)->success(),
            'serve_eff' => $this->skill(Skill::Serve)->efficiency(),
            'serve_errors' => $this->skill(Skill::Serve)->errorRate(),
            'reception_pos' => $this->skill(Skill::Reception)->success(),
            'reception_perf' => $this->skill(Skill::Reception)->perfect(),
            'reception_eff' => $this->skill(Skill::Reception)->efficiency(),
            'attack_success' => $this->skill(Skill::Attack)->success(),
            'attack_eff' => $this->skill(Skill::Attack)->efficiency(),
            'block_points' => $per($this->skill(Skill::Block)->points()),
            'set_eff' => $this->skill(Skill::Set)->efficiency(),
            'dig_pos' => $this->skill(Skill::Dig)->success(),
            'errors_given' => $per($this->errorsGiven()),
            'opponent_errors' => $per($this->pointsWon['opponent_error'] ?? 0),
        ];
    }
}
