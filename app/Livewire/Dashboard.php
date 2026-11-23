<?php

namespace App\Livewire;

use App\Enums\AnalysisStatus;
use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\Player;
use App\Stats\GameStatsCalculator;
use App\Stats\PlayerStatLine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    use InteractsWithClub;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:clubs.'.$this->club()->id.',.analysis.updated' => '$refresh',
        ];
    }

    public function render(GameStatsCalculator $calculator): View
    {
        $club = $this->club();
        $user = $this->user();
        $isStaff = $user->isStaffOf($club);

        $gamesQuery = Game::query()->forClub($club)->with('team');
        $children = collect();

        if (! $isStaff && ! $user->hasRole($club, Role::Player)) {
            $children = $user->children()->where('players.club_id', $club->id)->with('team')->get();
            $gamesQuery->whereIn('team_id', $children->pluck('team_id')->filter()->unique());
        }

        $recent = (clone $gamesQuery)->latest('played_at')->limit(6)->get();
        $inProgress = (clone $gamesQuery)
            ->whereIn('analysis_status', [AnalysisStatus::Queued, AnalysisStatus::Processing])
            ->get();

        $leaders = collect();
        $lastGame = $recent->first(fn (Game $g) => $g->actions()->exists());

        if ($lastGame !== null) {
            $leaders = $calculator->forGame($lastGame)->lines
                ->filter(fn (PlayerStatLine $l) => $l->points > 0)
                ->sortByDesc(fn (PlayerStatLine $l) => $l->points)
                ->take(5)
                ->values();
        }

        return view('livewire.dashboard', [
            'club' => $club,
            'isStaff' => $isStaff,
            'children' => $children,
            'recent' => $recent,
            'inProgress' => $inProgress,
            'lastGame' => $lastGame,
            'leaders' => $leaders,
            'kpi' => $this->kpis($club->id, $recent),
        ]);
    }

    /**
     * @param  Collection<int, Game>  $recent
     * @return array<string, int|string>
     */
    private function kpis(int $clubId, Collection $recent): array
    {
        $season = Game::query()->forClub($clubId)->where('played_at', '>=', now()->subYear());

        return [
            'games' => (clone $season)->count(),
            'wins' => (clone $season)->whereColumn('sets_won', '>', 'sets_lost')->count(),
            'players' => Player::query()->forClub($clubId)->where('is_active', true)->count(),
            'actions' => GameAction::query()->forClub($clubId)->count(),
        ];
    }
}
