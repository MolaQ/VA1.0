<?php

namespace App\Stats\Report;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GameRally;
use App\Models\GameSet;
use Illuminate\Support\Collection;

/**
 * Rebuilds the rallies of a match from the coded actions.
 *
 * Works the same for manual coding and for AI actions: everything is inferred from
 * the touches (serve => we served, reception => they served, ace/kill/error => winner),
 * explicit outcomes in `game_rallies` win, and the gaps are filled from the rules of the
 * game: the winner of a rally serves the next one, we rotate after every side-out.
 */
final class RallyBuilder
{
    /**
     * @param  Collection<int, GameAction>  $actions  all actions of the match (any order)
     * @return Collection<int, Rally>
     */
    public function build(Game $game, Collection $actions): Collection
    {
        $game->loadMissing(['sets.lineups', 'rallies']);
        $explicit = $game->rallies->keyBy(fn (GameRally $r) => $r->game_set_id.':'.$r->number);
        $bySet = $actions->filter(fn (GameAction $a) => $a->rally !== null && $a->game_set_id !== null)->groupBy('game_set_id');

        $rallies = collect();

        foreach ($game->sets as $set) {
            $rallies = $rallies->concat($this->buildSet($set, $bySet->get($set->id, collect()), $explicit));
        }

        return $rallies->values();
    }

    /**
     * @param  Collection<int, GameAction>  $actions
     * @param  Collection<string, GameRally>  $explicit
     * @return array<int, Rally>
     */
    private function buildSet(GameSet $set, Collection $actions, Collection $explicit): array
    {
        $byRally = $actions->groupBy('rally');
        $numbers = $byRally->keys()->map(fn ($n) => (int) $n)
            ->merge($explicit->filter(fn (GameRally $r) => (int) $r->game_set_id === (int) $set->id)->pluck('number'))
            ->unique()->sort()->values();

        /** @var array<int, Rally> $list */
        $list = [];

        foreach ($numbers as $n) {
            $touches = $byRally->get($n, collect())
                ->sortBy([fn ($a, $b) => ($a->video_time ?? INF) <=> ($b->video_time ?? INF), fn ($a, $b) => $a->id <=> $b->id])
                ->values();
            $rally = new Rally((int) $set->number, $n, $touches);
            $known = $explicit->get($set->id.':'.$n);
            $rally->server = $known?->server ?? $rally->inferredServer();
            $rally->winner = $known?->winner ?? $rally->inferredWinner();
            $list[] = $rally;
        }

        // Gaps: the winner of rally k serves rally k+1 (only for consecutive numbers).
        foreach ($list as $i => $rally) {
            $prev = $list[$i - 1] ?? null;
            $next = $list[$i + 1] ?? null;

            if ($rally->server === null) {
                $rally->server = match (true) {
                    $prev !== null && $prev->number === $rally->number - 1 && $prev->winner !== null => $prev->winner,
                    $i === 0 && $rally->number === 1 && $set->we_serve_first !== null => $set->we_serve_first ? Rally::US : Rally::THEM,
                    default => null,
                };
            }

            if ($rally->winner === null && $next !== null && $next->number === $rally->number + 1) {
                $rally->winner = $next->server ?? $next->inferredServer();
            }
        }

        $this->rotate($set, $list);

        return $list;
    }

    /**
     * Rotation and running score. Rotation k means starting position k stands in zone I;
     * we move one position after every side-out (a rally won on the opponent's serve).
     *
     * @param  array<int, Rally>  $list
     */
    private function rotate(GameSet $set, array $list): void
    {
        $hasLineup = $set->lineups->whereNotNull('rotation_position')->isNotEmpty();
        $turns = 0;
        $us = 0;
        $them = 0;

        foreach ($list as $rally) {
            $rally->rotation = $hasLineup ? ($turns % 6) + 1 : null;
            $rally->scoreUs = $us;
            $rally->scoreThem = $them;

            if ($rally->winner === Rally::US) {
                $us++;

                if ($rally->server === Rally::THEM) {
                    $turns++;
                }
            } elseif ($rally->winner === Rally::THEM) {
                $them++;
            }
        }
    }
}
