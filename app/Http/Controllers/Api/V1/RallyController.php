<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionSource;
use App\Events\GameActionsRecorded;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameRally;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RallyController extends Controller
{
    /**
     * Batch upsert of rally outcomes (who served, who won) read by the analyzer from the
     * scoreboard. They feed side-out / break-point and rotation stats even before the AI
     * recognises single touches. Outcomes entered by a trainer are never overwritten.
     */
    public function store(Request $request, Game $game): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('analysis:write') && $request->user()->can('code', $game), 403);

        $side = ['nullable', Rule::in([GameRally::US, GameRally::THEM])];
        $data = $request->validate([
            'rallies' => ['required', 'array', 'min:1', 'max:1000'],
            'rallies.*.set' => ['required', 'integer', 'between:1,5'],
            'rallies.*.rally' => ['required', 'integer', 'min:1', 'max:999'],
            'rallies.*.server' => $side,
            'rallies.*.winner' => $side,
            'rallies.*.video_time' => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ]);

        $saved = 0;
        $kept = 0;

        DB::transaction(function () use ($game, $data, &$saved, &$kept) {
            $sets = $game->sets()->get()->keyBy('number');

            foreach ($data['rallies'] as $item) {
                $set = $sets->get((int) $item['set']) ?? $game->sets()->create(['number' => (int) $item['set']]);
                $sets->put((int) $item['set'], $set);

                $rally = GameRally::firstOrNew(['game_set_id' => $set->id, 'number' => (int) $item['rally']]);

                if ($rally->exists && $rally->source !== ActionSource::Ai) {
                    $kept++;

                    continue;
                }

                $rally->fill([
                    'game_id' => $game->id,
                    'server' => $item['server'] ?? null,
                    'winner' => $item['winner'] ?? null,
                    'video_time' => $item['video_time'] ?? null,
                    'source' => ActionSource::Ai,
                ])->save();
                $saved++;
            }
        });

        GameActionsRecorded::dispatch($game, 0, $saved, 0);

        return response()->json(['saved' => $saved, 'kept' => $kept]);
    }
}
