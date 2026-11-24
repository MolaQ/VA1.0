<?php

namespace App\Livewire\Games;

use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Matches')]
class GameIndex extends Component
{
    use InteractsWithClub, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $team = null;

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'team'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:clubs.'.$this->club()->id.',.analysis.updated' => '$refresh'];
    }

    public function render(): View
    {
        $club = $this->club();
        $user = $this->user();

        $query = Game::query()->forClub($club)->with('team')
            ->when($this->search !== '', fn ($q) => $q->where('opponent', 'like', '%'.$this->search.'%'))
            ->when($this->team, fn ($q) => $q->where('team_id', $this->team))
            ->latest('played_at');

        if (! $user->isStaffOf($club) && ! $user->hasRole($club, Role::Player)) {
            $query->whereIn('team_id', $user->children()->where('players.club_id', $club->id)->pluck('team_id')->filter());
        }

        return view('livewire.games.index', [
            'games' => $query->paginate(12),
            'teams' => $club->teams()->orderBy('name')->get(),
            'canCreate' => $user->can('create', [Game::class, $club]),
            'canCompare' => $user->is_super_admin || $user->hasRole($club, Role::Admin, Role::Trainer, Role::Player),
        ]);
    }
}
