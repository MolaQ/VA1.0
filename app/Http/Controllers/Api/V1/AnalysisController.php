<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalysisStatus;
use App\Events\GameAnalysisUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAnalysisRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;

class AnalysisController extends Controller
{
    /**
     * Progress reports from the analyzer (queued → processing 0-100% → completed / failed).
     */
    public function update(UpdateAnalysisRequest $request, Game $game): JsonResponse
    {
        $status = AnalysisStatus::from($request->validated('status'));

        $game->forceFill([
            'analysis_status' => $status,
            'analysis_progress' => $status === AnalysisStatus::Completed ? 100 : (int) ($request->validated('progress') ?? $game->analysis_progress),
            'analysis_message' => $request->validated('message'),
            'analysis_updated_at' => now(),
        ])->save();

        GameAnalysisUpdated::dispatch($game);

        return response()->json([
            'status' => $game->analysis_status->value,
            'progress' => $game->analysis_progress,
        ]);
    }
}
