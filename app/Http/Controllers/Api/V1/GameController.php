<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GameController extends Controller
{
    /**
     * Match metadata + roster so the analyzer can map jersey numbers to players.
     */
    public function show(Game $game): JsonResponse
    {
        Gate::authorize('view', $game);

        $game->load(['team', 'sets']);

        return response()->json([
            'data' => [
                'id' => $game->id,
                'club_id' => $game->club_id,
                'team' => ['id' => $game->team_id, 'name' => $game->team->name],
                'opponent' => $game->opponent,
                'played_at' => $game->played_at->toIso8601String(),
                'youtube_id' => $game->youtube_id,
                'youtube_url' => $game->youtube_url,
                'analysis' => [
                    'status' => $game->analysis_status->value,
                    'progress' => $game->analysis_progress,
                ],
                'sets' => $game->sets->map->only(['number', 'score_us', 'score_them', 'video_start'])->values(),
                'roster' => Player::query()->forClub($game->club_id)->where('team_id', $game->team_id)->get()
                    ->map(fn (Player $p) => [
                        'id' => $p->id,
                        'number' => $p->number,
                        'name' => $p->full_name,
                        'position' => $p->position?->value,
                    ])->values(),
            ],
        ]);
    }
}
