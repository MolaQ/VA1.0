<?php

namespace App\Stats\Report;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Several matches side by side: team numbers per match, the total over all of them,
 * and every player's numbers per match.
 */
final class MatchComparison
{
    /**
     * Key numbers: label, percent or count, "higher is better", "good" threshold.
     *
     * @return array<string, array{label: string, pct: bool, higher: bool, good: ?float}>
     */
    public static function kpiDefinitions(): array
    {
        return [
            'side_out' => ['label' => __('Side-out'), 'pct' => true, 'higher' => true, 'good' => 0.6],
            'break_point' => ['label' => __('Break point'), 'pct' => true, 'higher' => true, 'good' => 0.4],
            'serve_success' => ['label' => __('Serve').': '.__('Aces'), 'pct' => true, 'higher' => true, 'good' => 0.1],
            'serve_eff' => ['label' => __('Serve efficiency'), 'pct' => true, 'higher' => true, 'good' => 0.1],
            'serve_errors' => ['label' => __('Serve errors'), 'pct' => true, 'higher' => false, 'good' => null],
            'reception_pos' => ['label' => __('Positive reception'), 'pct' => true, 'higher' => true, 'good' => 0.55],
            'reception_perf' => ['label' => __('Reception').': '.__('Perfect'), 'pct' => true, 'higher' => true, 'good' => 0.35],
            'reception_eff' => ['label' => __('Reception').': '.__('Efficiency'), 'pct' => true, 'higher' => true, 'good' => 0.35],
            'attack_success' => ['label' => __('Attack success'), 'pct' => true, 'higher' => true, 'good' => 0.5],
            'attack_eff' => ['label' => __('Attack').': '.__('Efficiency'), 'pct' => true, 'higher' => true, 'good' => 0.35],
            'block_points' => ['label' => __('Block points').' / '.__('match'), 'pct' => false, 'higher' => true, 'good' => null],
            'set_eff' => ['label' => __('Setting').': '.__('Efficiency'), 'pct' => true, 'higher' => true, 'good' => 0.5],
            'dig_pos' => ['label' => __('Dig').': '.__('Positive (#+)'), 'pct' => true, 'higher' => true, 'good' => 0.5],
            'errors_given' => ['label' => __('Points given away').' / '.__('match'), 'pct' => false, 'higher' => false, 'good' => null],
            'opponent_errors' => ['label' => __('Opponent errors').' / '.__('match'), 'pct' => false, 'higher' => true, 'good' => null],
        ];
    }

    /** @var array<int, TeamTotals> game id => team numbers */
    public array $matches = [];

    public TeamTotals $total;

    /** @var array<int, array{player: Player, games: array<int, PlayerReport>, total: PlayerReport}> player id => lines */
    public array $players = [];

    /**
     * @param  Collection<int, Game>  $games  in display order
     */
    public function __construct(public readonly Collection $games, MatchReportBuilder $builder)
    {
        $this->total = new TeamTotals;

        foreach ($games as $game) {
            $report = $builder->build($game);
            $this->matches[$game->id] = TeamTotals::fromReport($report);
            $this->total->add($report);

            foreach ($report->players as $line) {
                if ($line->player === null || $line->touches() === 0) {
                    continue;
                }

                $id = $line->player->id;
                $this->players[$id] ??= ['player' => $line->player, 'games' => [], 'total' => new PlayerReport($line->player)];
                $this->players[$id]['games'][$game->id] = $line;

                foreach ($line->skills as $key => $skill) {
                    $this->players[$id]['total']->skills[$key]->merge($skill);
                }

                foreach (array_keys($line->sets) as $set) {
                    $this->players[$id]['total']->sets[$game->id * 10 + $set] = true;
                }
            }
        }

        uasort($this->players, fn (array $a, array $b) => ($b['total']->valuePerSet() ?? -INF) <=> ($a['total']->valuePerSet() ?? -INF));
    }

    /**
     * Change of the latest match against the average of the earlier ones (null when not comparable).
     */
    public function trend(string $kpi): ?float
    {
        if (count($this->matches) < 2) {
            return null;
        }

        $latest = $this->games->sortByDesc('played_at')->first();

        if ($latest === null) {
            return null;
        }

        $values = collect($this->matches)->except([$latest->id])->map(fn (TeamTotals $t) => $t->kpis()[$kpi])->filter(fn ($v) => $v !== null);
        $now = $this->matches[$latest->id]->kpis()[$kpi];

        return $now === null || $values->isEmpty() ? null : $now - (float) $values->avg();
    }
}
