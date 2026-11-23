<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Player;
use App\Models\SetLineup;
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

        return response()->json(['data' => self::payload($game)]);
    }

    /**
     * Match data the analyzer needs: video, roster (numbers -> players), sets with lineups.
     *
     * @return array<string, mixed>
     */
    public static function payload(Game $game): array
    {
        $game->load(['team', 'sets.lineups.player']);

        return [

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
            'sets' => $game->sets->map(fn (GameSet $set) => [
                ...$set->only(['number', 'score_us', 'score_them', 'video_start', 'we_serve_first']),
                // starting rotation: position 1 (serves first) .. 6
                'lineup' => $set->lineups->whereNotNull('rotation_position')->sortBy('rotation_position')
                    ->map(fn (SetLineup $l) => [
                        'rotation_position' => $l->rotation_position,
                        'player_id' => $l->player_id,
                        'number' => $l->player?->number,
                    ])->values(),
            ])->values(),
            'roster' => Player::query()->forClub($game->club_id)->where('team_id', $game->team_id)->get()
                ->map(fn (Player $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'name' => $p->full_name,
                    'position' => $p->position?->value,
                ])->values(),
        ];
    }
}
