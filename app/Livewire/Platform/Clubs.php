<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Club;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Platform owner (super admin) view of every club using the SaaS.
 */
#[Title('Clubs')]
class Clubs extends Component
{
    use InteractsWithClub;

    public function mount(): void
    {
        abort_unless($this->user()->is_super_admin, 403);
    }

    public function enter(int $clubId): void
    {
        abort_unless($this->user()->is_super_admin, 403);

        $this->user()->switchClub(Club::findOrFail($clubId));
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.platform.clubs', [
            'clubs' => Club::query()->withCount(['memberships', 'players', 'games'])->orderBy('name')->get(),
        ]);
    }
}
