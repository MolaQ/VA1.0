<?php

namespace App\Livewire\Club;

use App\Actions\CreateClub;
use App\Livewire\Concerns\InteractsWithClub;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Teams')]
class Teams extends Component
{
    use InteractsWithClub;

    public ?int $editingId = null;

    public string $name = '';

    public string $category = '';

    public string $season = '';

    public function mount(): void
    {
        Gate::authorize('manage', $this->club());
        $this->season = CreateClub::currentSeason();
    }

    public function edit(int $id): void
    {
        $team = $this->club()->teams()->findOrFail($id);
        $this->editingId = $team->id;
        $this->name = $team->name;
        $this->category = (string) $team->category;
        $this->season = (string) $team->season;
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'category']);
        $this->season = CreateClub::currentSeason();
    }

    public function save(): void
    {
        Gate::authorize('manage', $this->club());

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'season' => ['nullable', 'string', 'max:9'],
        ]);

        $this->editingId
            ? $this->club()->teams()->findOrFail($this->editingId)->update($data)
            : $this->club()->teams()->create($data);

        $this->cancel();
        $this->toast(__('Team saved.'));
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage', $this->club());

        $team = $this->club()->teams()->withCount('games')->findOrFail($id);

        if ($team->games_count > 0) {
            $this->toast(__('A team with matches cannot be deleted.'), 'danger');

            return;
        }

        $team->delete();
        $this->toast(__('Team deleted.'));
    }

    public function render(): View
    {
        return view('livewire.club.teams', [
            'teams' => $this->club()->teams()->withCount(['players', 'games'])->orderBy('name')->get(),
        ]);
    }
}
