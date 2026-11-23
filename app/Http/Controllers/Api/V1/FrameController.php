<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionSource;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameFrame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrameController extends Controller
{
    /**
     * Player positions found by the analyzer (2D court view). Idempotent by video time:
     * resending a window overwrites the AI frames at those times. Frames placed by the
     * analyst (source manual) are separate rows and are never touched.
     */
    public function store(Request $request, Game $game): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('analysis:write') && $request->user()->can('code', $game), 403);

        $data = $request->validate([
            'frames' => ['required', 'array', 'min:1', 'max:2000'],
            'frames.*.t' => ['required', 'numeric', 'min:0', 'max:86400'],
            'frames.*.players' => ['present', 'array', 'max:'.GameFrame::MAX_PLAYERS],
            'frames.*.players.*.x' => ['required', 'numeric'],
            'frames.*.players.*.y' => ['required', 'numeric'],
            'frames.*.players.*.team' => ['nullable', 'in:us,them'],
            'frames.*.players.*.n' => ['nullable', 'integer', 'between:0,99'],
            'frames.*.players.*.k' => ['nullable', 'integer', 'min:0'],
            'frames.*.players.*.f' => ['nullable', 'numeric'],
        ]);

        $now = now();
        $rows = collect($data['frames'])
            ->keyBy(fn (array $f) => number_format((float) $f['t'], 2, '.', ''))
            ->map(fn (array $f, string $t) => [
                'game_id' => $game->id,
                't' => $t,
                'source' => ActionSource::Ai->value,
                'players' => json_encode(GameFrame::clean($f['players'])),
                'created_at' => $now,
                'updated_at' => $now,
            ])->values();

        foreach ($rows->chunk(500) as $chunk) {
            GameFrame::query()->upsert($chunk->all(), ['game_id', 'source', 't'], ['players', 'updated_at']);
        }

        return response()->json(['saved' => $rows->count()]);
    }

    /**
     * Wipe the AI positions before a re-analysis (the analyst's frames stay).
     */
    public function destroy(Request $request, Game $game): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('analysis:write') && $request->user()->can('code', $game), 403);

        return response()->json([
            'deleted' => $game->frames()->where('source', ActionSource::Ai->value)->delete(),
        ]);
    }
}
