<?php

namespace App\Livewire\Club;

use App\Livewire\Concerns\InteractsWithClub;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * API tokens for the AI video-analysis pipeline (Sanctum, ability `analysis:write`).
 */
#[Title('AI integrations')]
class Integrations extends Component
{
    use InteractsWithClub;

    public string $tokenName = 'AI analyzer';

    public ?string $plainToken = null;

    public function mount(): void
    {
        Gate::authorize('manage', $this->club());
    }

    public function createToken(): void
    {
        Gate::authorize('manage', $this->club());
        $this->validate(['tokenName' => ['required', 'string', 'max:60']]);

        $this->plainToken = $this->user()
            ->createToken($this->tokenName.' · '.$this->club()->name, ['analysis:write'])
            ->plainTextToken;

        $this->toast(__('Token created. Copy it now.'));
    }

    public function revoke(int $tokenId): void
    {
        $this->user()->tokens()->whereKey($tokenId)->delete();
        $this->toast(__('Token revoked.'));
    }

    public function render(): View
    {
        return view('livewire.club.integrations', [
            'tokens' => $this->user()->tokens()->latest()->get(),
            'apiBase' => url('/api/v1'),
            'analyzerConfigured' => filled(config('services.analyzer.url')),
        ]);
    }
}
