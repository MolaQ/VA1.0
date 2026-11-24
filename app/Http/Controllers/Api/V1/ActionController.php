<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreActionsRequest;
use App\Models\Game;
use App\Services\ActionIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActionController extends Controller
{
    /**
     * Batch upsert of actions. Items with an `external_id` are idempotent, so the
     * analyzer can safely resend a window of detections.
     */
    public function store(StoreActionsRequest $request, Game $game, ActionIngestor $ingestor): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $request->validated('actions');

        $result = $ingestor->ingest($game, $items, ActionSource::Ai, $request->user());

        return response()->json([
            'created' => $result->created,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'ids' => $result->actions->pluck('id'),
        ], $result->created > 0 ? 201 : 200);
    }

    /**
     * Wipe actions (default: only AI-generated ones) before a re-analysis.
     */
    public function destroy(Request $request, Game $game, ActionIngestor $ingestor): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('analysis:write') && $request->user()->can('code', $game), 403);

        $source = $request->validate([
            'source' => ['nullable', Rule::enum(ActionSource::class)],
        ])['source'] ?? ActionSource::Ai->value;

        return response()->json([
            'deleted' => $ingestor->purge($game, ActionSource::from($source)),
        ]);
    }
}
