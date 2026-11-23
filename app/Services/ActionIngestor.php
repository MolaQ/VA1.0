<?php

namespace App\Services;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GameSet;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for writing scouting actions into a match.
 *
 * Used by the AI pipeline API (batch, idempotent via `external_id`) and by the
 * trainer's manual coding screen (fallback / corrections). After every write a
 * broadcast event lets open screens refresh in real time.
 */
class ActionIngestor
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function ingest(Game $game, array $items, ActionSource $source, ?User $by = null): IngestResult
    {
        $roster = $this->roster($game);
        $sets = $game->sets()->get()->keyBy('number');

        $created = 0;
        $updated = 0;
        $skipped = [];
        $saved = collect();

        DB::transaction(function () use ($game, $items, $source, $by, $roster, &$sets, &$created, &$updated, &$skipped, $saved) {
            foreach (array_values($items) as $index => $item) {
                $player = $this->resolvePlayer($roster, $item, $game->team_id);

                if ($player === false && Arr::get($item, 'player_id') === null) {
                    // A jersey number we don't know yet: add it to the team as "#47", name it later.
                    $player = Player::create([
                        'club_id' => $game->club_id,
                        'team_id' => $game->team_id,
                        'number' => (int) $item['player_number'],
                    ]);
                    $roster->push($player);
                }

                if ($player === false) {
                    $skipped[] = ['index' => $index, 'message' => 'Player with the given id/number not found in the roster.'];

                    continue;
                }

                $setId = null;

                if (($number = Arr::get($item, 'set')) !== null) {
                    /** @var GameSet $set */
                    $set = $sets->get((int) $number) ?? $game->sets()->create(['number' => (int) $number]);
                    $sets->put((int) $number, $set);
                    $setId = $set->id;
                }

                $attributes = [
                    'club_id' => $game->club_id,
                    'game_set_id' => $setId,
                    'player_id' => $player?->id,
                    'rally' => Arr::get($item, 'rally'),
                    'skill' => Skill::from((string) $item['skill']),
                    'grade' => Grade::from((string) $item['grade']),
                    'video_time' => Arr::get($item, 'video_time'),
                    'zone_from' => Arr::get($item, 'zone_from'),
                    'zone_to' => Arr::get($item, 'zone_to'),
                    'source' => $source,
                    'confidence' => Arr::get($item, 'confidence'),
                    'meta' => Arr::get($item, 'meta'),
                    'needs_review' => $this->needsReview($item, $player, $source),
                    'review_note' => Arr::get($item, 'review_note'),
                ];

                $externalId = Arr::get($item, 'external_id');

                if ($externalId !== null) {
                    /** @var GameAction $action */
                    $action = $game->actions()->firstOrNew(['external_id' => (string) $externalId]);
                } else {
                    $action = $game->actions()->make();
                }

                $isNew = ! $action->exists;

                // A person already confirmed or corrected this one: the analyzer must not undo it.
                if (! $isNew && $action->reviewed_at !== null && $source === ActionSource::Ai) {
                    $saved->push($action);

                    continue;
                }

                $action->fill($attributes);

                if ($isNew) {
                    $action->created_by = $by?->id;
                }

                $action->save();
                $isNew ? $created++ : $updated++;
                $saved->push($action);
            }
        });

        if ($saved->isNotEmpty()) {
            $saved->each(fn (GameAction $a) => $a->setRelation('set', $sets->firstWhere('id', $a->game_set_id)));

            GameActionsRecorded::dispatch(
                $game,
                $created,
                $updated,
                0,
                $saved->map(fn (GameAction $a) => $a->toBroadcast())->values()->all(),
            );
        }

        return new IngestResult($saved, $created, $updated, $skipped);
    }

    /**
     * Remove actions (e.g. before re-running AI analysis) and notify listeners.
     */
    public function purge(Game $game, ?ActionSource $source = null): int
    {
        $deleted = $game->actions()
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->delete();

        // Rally outcomes read by the analyzer go with its actions; a trainer's stay.
        $game->rallies()
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->delete();

        if ($deleted > 0) {
            GameActionsRecorded::dispatch($game, 0, 0, $deleted);
        }

        return $deleted;
    }

    public function delete(GameAction $action): void
    {
        $game = $action->game;
        $action->delete();

        GameActionsRecorded::dispatch($game, 0, 0, 1);
    }

    /**
     * AI actions without a recognised player or with low confidence wait for the analyst;
     * anyone can also flag an action explicitly.
     *
     * @param  array<string, mixed>  $item
     */
    private function needsReview(array $item, ?Player $player, ActionSource $source): bool
    {
        if (($flag = Arr::get($item, 'needs_review')) !== null) {
            return (bool) $flag;
        }

        if ($source !== ActionSource::Ai) {
            return false;
        }

        $confidence = Arr::get($item, 'confidence');

        return $player === null || (is_numeric($confidence) && (float) $confidence < GameAction::REVIEW_CONFIDENCE);
    }

    /**
     * @return Collection<int, Player>
     */
    private function roster(Game $game): Collection
    {
        return Player::query()->forClub($game->club_id)->get();
    }

    /**
     * @param  Collection<int, Player>  $roster
     * @param  array<string, mixed>  $item
     * @return Player|false|null null = no player (team/opponent action), false = unknown player
     */
    private function resolvePlayer(Collection $roster, array $item, int $teamId): Player|false|null
    {
        if (($id = Arr::get($item, 'player_id')) !== null) {
            return $roster->firstWhere('id', (int) $id) ?? false;
        }

        if (($number = Arr::get($item, 'player_number')) !== null) {
            return $roster
                ->filter(fn (Player $p) => $p->number === (int) $number)
                ->sortByDesc(fn (Player $p) => $p->team_id === $teamId)
                ->first() ?? false;
        }

        return null;
    }
}
