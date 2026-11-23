<?php

namespace App\Livewire;

use App\Actions\CreateClub;
use App\Livewire\Concerns\InteractsWithClub;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Shown to a signed-in user who does not belong to any club yet.
 */
#[Layout('layouts::guest')]
#[Title('Welcome to VVA')]
class Onboarding extends Component
{
    use InteractsWithClub;

    #[Validate('required|string|max:120')]
    public string $club_name = '';

    public function mount(): void
    {
        if ($this->user()->clubCount() > 0) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function create(CreateClub $createClub): void
    {
        $this->validate();
        $createClub->handle($this->user(), $this->club_name);
        $this->redirectRoute('dashboard', navigate: true);
    }
}
