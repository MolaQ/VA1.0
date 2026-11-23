<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Events\GameAnalysisUpdated;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hands a match over to AI video analysis.
 *
 * Default (no VVA_ANALYZER_URL): the match is queued and the club's analysis agent
 * (a program on a computer with a GPU) takes it through POST /api/v1/agent/claim.
 *
 * Push mode (VVA_ANALYZER_URL set):
 * Contract sent to the analyzer (JSON POST to VVA_ANALYZER_URL):
 *   game_id, youtube_id, youtube_url, team, roster[{id, number, name, position}],
 *   api_base (where to push results: {api_base}/games/{id}/actions etc.)
 * The analyzer authenticates back with a club API token (Sanctum, ability `analysis:write`).
 */
class RequestVideoAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public Game $game) {}

    public function handle(): void
    {
        $game = $this->game->fresh(['team']);

        if ($game === null || $game->youtube_id === null) {
            return;
        }

        $url = config('services.analyzer.url');

        if (blank($url)) {
            $this->update($game, AnalysisStatus::Queued, 0, __('Waiting for the club\'s analysis agent.'));

            return;
        }

        $roster = Player::query()->forClub($game->club_id)->where('team_id', $game->team_id)->get()
            ->map(fn (Player $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'name' => $p->full_name,
                'position' => $p->position?->value,
            ])->values()->all();

        try {
            Http::withToken((string) config('services.analyzer.token'))
                ->timeout((int) config('services.analyzer.timeout', 15))
                ->acceptJson()
                ->post((string) $url, [
                    'game_id' => $game->id,
                    'club_id' => $game->club_id,
                    'youtube_id' => $game->youtube_id,
                    'youtube_url' => $game->youtube_url,
                    'team' => $game->team->name,
                    'opponent' => $game->opponent,
                    'roster' => $roster,
                    'api_base' => url('/api/v1'),
                ])
                ->throw();

            $this->update($game, AnalysisStatus::Queued, 0, __('Match sent for AI analysis.'));
        } catch (Throwable $e) {
            report($e);
            $this->update($game, AnalysisStatus::Failed, 0, __('Analysis could not be sent: :error', ['error' => $e->getMessage()]));
        }
    }

    private function update(Game $game, AnalysisStatus $status, int $progress, string $message): void
    {
        $game->forceFill([
            'analysis_status' => $status,
            'analysis_progress' => $progress,
            'analysis_message' => $message,
            'analysis_updated_at' => now(),
        ])->save();

        GameAnalysisUpdated::dispatch($game);
    }
}
