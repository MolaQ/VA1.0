<?php

namespace App\Livewire\Games;

use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Stats\Report\MatchComparison;
use App\Stats\Report\MatchReportBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Match comparison: the key team numbers and every player's numbers, match by match,
 * with the total over the chosen matches and the trend of the latest one.
 */
class GameCompare extends Component
{
    use InteractsWithClub;

    public const MAX = 10;

    #[Url]
    public ?int $team = null;

    /** @var array<int, int> chosen game ids */
    #[Url(as: 'm')]
    public array $selected = [];

    public function mount(): void
    {
        $user = $this->user();
        abort_unless($user->is_super_admin || $user->hasRole($this->club(), Role::Admin, Role::Trainer, Role::Player), 403);

        if ($this->selected === []) {
            $this->selected = $this->candidates()->take(5)->pluck('id')->all();
        }
    }

    public function updatedTeam(): void
    {
        $this->selected = $this->candidates()->take(5)->pluck('id')->all();
    }

    public function toggle(int $gameId): void
    {
        $ids = array_map('intval', $this->selected);

        if (in_array($gameId, $ids, true)) {
            $ids = array_values(array_diff($ids, [$gameId]));
        } elseif (count($ids) < self::MAX && $this->candidates()->contains('id', $gameId)) {
            $ids[] = $gameId;
        }

        $this->selected = $ids;
    }

    /**
     * @return Collection<int, Game>
     */
    private function candidates(): Collection
    {
        return Game::query()->forClub($this->club())->with('team')
            ->when($this->team, fn ($q) => $q->where('team_id', $this->team))
            ->latest('played_at')->limit(40)->get();
    }

    public function render(MatchReportBuilder $builder): View
    {
        $candidates = $this->candidates();
        $ids = array_map('intval', $this->selected);
        $games = $candidates->filter(fn (Game $g) => in_array($g->id, $ids, true))->sortBy('played_at')->values();

        return view('livewire.games.compare', [
            'candidates' => $candidates,
            'teams' => $this->club()->teams()->orderBy('name')->get(),
            'comparison' => new MatchComparison($games, $builder),
            'kpis' => MatchComparison::kpiDefinitions(),
        ])->title(__('Match comparison'));
    }
}
