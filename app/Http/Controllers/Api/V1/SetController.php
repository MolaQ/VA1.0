<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateSetRequest;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SetController extends Controller
{
    /**
     * Create or update a set: score, where it starts in the video and the lineup.
     */
    public function update(UpdateSetRequest $request, Game $game, int $number): JsonResponse
    {
        abort_unless($number >= 1 && $number <= 5, 404);

        $data = $request->validated();

        $set = DB::transaction(function () use ($game, $number, $data) {
            $set = $game->sets()->firstOrCreate(['number' => $number]);
            $set->fill(collect($data)->only(['score_us', 'score_them', 'video_start'])->all())->save();

            if (array_key_exists('lineup', $data)) {
                $roster = Player::query()->forClub($game->club_id)->get();
                $set->lineups()->delete();

                foreach ($data['lineup'] as $entry) {
                    $player = isset($entry['player_id'])
                        ? $roster->firstWhere('id', (int) $entry['player_id'])
                        : $roster->where('number', (int) $entry['player_number'])
                            ->sortByDesc(fn (Player $p) => $p->team_id === $game->team_id)
                            ->first();

                    if ($player === null) {
                        continue;
                    }

                    $set->lineups()->updateOrCreate(
                        ['player_id' => $player->id],
                        [
                            'rotation_position' => $entry['rotation_position'] ?? null,
                            'is_substitute' => (bool) ($entry['is_substitute'] ?? ($entry['rotation_position'] ?? null) === null),
                        ],
                    );
                }
            }

            return $set;
        });

        $game->refreshResult();

        GameAnalysisUpdated::dispatch($game);
        GameActionsRecorded::dispatch($game);

        return response()->json(['data' => $set->load('lineups')]);
    }
}
