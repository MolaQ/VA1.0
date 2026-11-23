<?php

namespace App\Livewire\Players;

use App\Enums\Position;
use App\Enums\Role;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Player;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Players')]
class PlayerIndex extends Component
{
    use InteractsWithClub;

    public ?int $editingId = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $nickname = '';

    public ?int $number = null;

    public ?string $position = null;

    public ?int $team_id = null;

    public ?string $birth_date = null;

    public bool $is_active = true;

    public string $search = '';

    public function create(): void
    {
        Gate::authorize('create', [Player::class, $this->club()]);
        $this->resetForm();
        $this->team_id = $this->club()->teams()->value('id');
        $this->dispatch('open-modal', 'player-form');
    }

    public function edit(int $id): void
    {
        $player = $this->club()->players()->findOrFail($id);
        Gate::authorize('update', $player);

        $this->resetValidation();
        $this->editingId = $player->id;
        $this->first_name = (string) $player->first_name;
        $this->last_name = (string) $player->last_name;
        $this->nickname = (string) $player->nickname;
        $this->number = $player->number;
        $this->position = $player->position?->value;
        $this->team_id = $player->team_id;
        $this->birth_date = $player->birth_date?->format('Y-m-d');
        $this->is_active = $player->is_active;
        $this->dispatch('open-modal', 'player-form');
    }

    public function save(): void
    {
        $club = $this->club();

        $data = $this->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'nickname' => ['nullable', 'string', 'max:40'],
            'number' => ['nullable', 'required_without_all:first_name,last_name,nickname', 'integer', 'between:0,99'],
            'position' => ['nullable', Rule::enum(Position::class)],
            'team_id' => ['nullable', Rule::exists('teams', 'id')->where('club_id', $club->id)],
            'birth_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);

        foreach (['first_name', 'last_name', 'nickname'] as $field) {
            $data[$field] = filled($data[$field]) ? trim((string) $data[$field]) : null;
        }

        if ($this->editingId) {
            $player = $club->players()->findOrFail($this->editingId);
            Gate::authorize('update', $player);
            $player->update($data);
        } else {
            Gate::authorize('create', [Player::class, $club]);
            $club->players()->create($data);
        }

        $this->dispatch('close-modal', 'player-form');
        $this->toast(__('Player saved.'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'first_name', 'last_name', 'nickname', 'number', 'position', 'team_id', 'birth_date']);
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render(): View
    {
        $club = $this->club();
        $user = $this->user();
        $isStaff = $user->isStaffOf($club);

        $query = Player::query()->forClub($club)->with('team')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('nickname', 'like', "%{$this->search}%")
                ->orWhere('number', $this->search)))
            ->orderByRaw('number is null, number')->orderBy('last_name');

        if (! $isStaff && ! $user->hasRole($club, Role::Player)) {
            $query->whereIn('id', $user->children()->pluck('players.id'));
        }

        return view('livewire.players.index', [
            'groups' => $query->get()->groupBy(fn (Player $p) => $p->team->name ?? __('No team')),
            'teams' => $club->teams()->orderBy('name')->get(),
            'positions' => Position::cases(),
            'isStaff' => $isStaff,
        ]);
    }
}
