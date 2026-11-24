<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Events\GameAnalysisUpdated;
use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pull queue for analysis agents: a program on a club computer with a GPU asks for the
 * next match to analyse, works on it locally and reports back through the normal API.
 */
class AgentController extends Controller
{
    /** A match "processing" without any report for this long is given to another agent. */
    public const STALE_MINUTES = 30;

    public function claim(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        abort_unless($user?->tokenCan('analysis:write'), 403);

        $clubIds = self::clubIds($request);

        $game = DB::transaction(function () use ($clubIds) {
            $game = Game::query()
                ->whereIn('club_id', $clubIds)
                ->whereNotNull('youtube_id')
                ->where(fn ($q) => $q
                    ->where('analysis_status', AnalysisStatus::Queued->value)
                    ->orWhere(fn ($q) => $q
                        ->where('analysis_status', AnalysisStatus::Processing->value)
                        ->where('analysis_updated_at', '<', now()->subMinutes(self::STALE_MINUTES))))
                ->orderBy('analysis_updated_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $game?->forceFill([
                'analysis_status' => AnalysisStatus::Processing,
                'analysis_progress' => 0,
                'analysis_message' => __('The analysis agent has taken the match.'),
                'analysis_updated_at' => now(),
                'analysis_started_at' => now(),
            ])->save();

            return $game;
        });

        if ($game === null) {
            return response()->noContent();
        }

        GameAnalysisUpdated::dispatch($game);

        return response()->json(['data' => GameController::payload($game)]);
    }

    /**
     * Clubs this token works for: the club it was created in (`club:{id}` ability),
     * or for older tokens every club where the user is staff.
     *
     * @return list<int>
     */
    public static function clubIds(Request $request): array
    {
        $user = $request->user();
        $abilities = (array) ($user->currentAccessToken()->abilities ?? []);

        $bound = collect($abilities)
            ->filter(fn ($a) => is_string($a) && str_starts_with($a, 'club:'))
            ->map(fn (string $a) => (int) substr($a, 5))
            ->values();

        $staff = $user->clubs()->get()->filter(fn ($club) => $user->isStaffOf($club->id))->pluck('id');

        return array_values(array_map(intval(...), ($bound->isNotEmpty() ? $bound->intersect($staff) : $staff)->all()));
    }
}
