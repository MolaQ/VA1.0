<?php

namespace App\Stats;

use App\Enums\Skill;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\Player;
use App\Models\SetLineup;
use Illuminate\Support\Collection;

/**
 * Builds the per-player box score of a match from its raw actions.
 *
 * Everything is derived from `game_actions`, so stats are always consistent with
 * what the AI pipeline (or a trainer) has recorded and can be recomputed at any time.
 */
final class GameStatsCalculator
{
    /**
     * @param  array<int, int>|null  $onlyPlayerIds  restrict lines to these players (e.g. a parent's children)
     */
    public function forGame(Game $game, ?int $setNumber = null, ?array $onlyPlayerIds = null): GameStats
    {
        $game->loadMissing(['sets.lineups']);

        $setNumbers = $game->sets->pluck('number')->map(fn ($n) => (int) $n)->all();
        $setIds = $setNumber !== null
            ? $game->sets->where('number', $setNumber)->pluck('id')->all()
            : null;

        $actions = $game->actions()
            ->with('set:id,number')
            ->when($setIds !== null, fn ($q) => $q->whereIn('game_set_id', $setIds))
            ->orderBy('video_time')
            ->orderBy('id')
            ->get();

        $players = Player::query()
            ->forClub($game->club_id)
            ->where(fn ($q) => $q
                ->where('team_id', $game->team_id)
                ->orWhereIn('id', $actions->pluck('player_id')->filter()->unique()))
            ->orderByRaw('number is null, number')
            ->orderBy('last_name')
            ->get();

        $lines = $players->mapWithKeys(fn (Player $p) => [$p->id => new PlayerStatLine($p)]);

        foreach ($game->sets as $set) {
            /** @var SetLineup $lineup */
            foreach ($set->lineups as $lineup) {
                $line = $lines->get($lineup->player_id);

                if ($line !== null) {
                    $line->sets[(int) $set->number] = $lineup->rotation_position !== null && ! $lineup->is_substitute
                        ? (string) $lineup->rotation_position
                        : '*';
                }
            }
        }

        $breakPointRallies = $this->servingRallies($actions);

        // Our team's actions not tied to a player yet (e.g. AI stage 1: serves before jersey numbers are read)
        $unassigned = new PlayerStatLine;

        foreach ($actions as $action) {
            if ($action->player_id === null) {
                $unassigned->add($action, $breakPointRallies->has($this->rallyKey($action)));

                continue;
            }

            $line = $lines->get($action->player_id);

            if ($line === null) {
                continue;
            }

            $line->add($action, $breakPointRallies->has($this->rallyKey($action)));
        }

        $totals = new PlayerStatLine;
        $lines->each(fn (PlayerStatLine $line) => $totals->merge($line));
        $totals->merge($unassigned);

        $visible = $lines->values();

        if ($onlyPlayerIds !== null) {
            $visible = $visible->filter(fn (PlayerStatLine $l) => in_array($l->player?->id, $onlyPlayerIds, true))->values();
        }

        return new GameStats($visible, $totals, $setNumbers);
    }

    /**
     * Rallies in which our team served: points scored there are break points.
     *
     * @param  Collection<int, GameAction>  $actions
     * @return Collection<string, bool>
     */
    private function servingRallies(Collection $actions): Collection
    {
        return $actions
            ->filter(fn (GameAction $a) => $a->skill === Skill::Serve && $a->rally !== null)
            ->mapWithKeys(fn (GameAction $a) => [$this->rallyKey($a) => true]);
    }

    private function rallyKey(GameAction $action): string
    {
        return ($action->game_set_id ?? 0).':'.($action->rally ?? 'x'.$action->id);
    }
}
