<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Club;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ClubSwitcher extends Component
{
    use InteractsWithClub;

    public function switchTo(int $clubId): void
    {
        $club = Club::findOrFail($clubId);
        abort_unless($this->user()->belongsToClub($club), 403);

        $this->user()->switchClub($club);
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.club-switcher', [
            'current' => $this->user()->currentClub,
            'clubs' => $this->user()->clubs()->orderBy('name')->get(),
        ]);
    }
}
