<?php

namespace App\Livewire\Club;

use App\Livewire\Concerns\InteractsWithClub;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Club settings')]
class Settings extends Component
{
    use InteractsWithClub;

    public string $name = '';

    public string $city = '';

    public string $accent_color = '#3B82F6';

    public function mount(): void
    {
        $club = $this->club();
        Gate::authorize('manage', $club);

        $this->name = $club->name;
        $this->city = (string) $club->city;
        $this->accent_color = $club->accent_color ?? '#3B82F6';
    }

    public function save(): void
    {
        $club = $this->club();
        Gate::authorize('manage', $club);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $club->update($data);
        $this->toast(__('Settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.club.settings');
    }
}
