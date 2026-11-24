<?php

namespace App\Livewire\Club;

use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;
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
            ->createToken($this->tokenName.' · '.$this->club()->name, ['analysis:write', 'club:'.$this->club()->id])
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
        $clubId = $this->club()->id;
        // the agent asks for work every few seconds, so a recently used club token means it is running
        $agentSeen = PersonalAccessToken::query()
            ->where('abilities', 'like', '%"club:'.$clubId.'"%')
            ->max('last_used_at');

        return view('livewire.club.integrations', [
            'tokens' => $this->user()->tokens()->latest()->get(),
            'agentOnline' => $agentSeen !== null && Carbon::parse($agentSeen)->gt(now()->subMinutes(2)),
            'agentSeen' => $agentSeen !== null ? Carbon::parse($agentSeen) : null,
            'jobs' => Game::query()->with('team')->where('club_id', $clubId)
                ->where(fn ($q) => $q->whereIn('analysis_status', ['queued', 'processing'])
                    ->orWhere(fn ($q) => $q->whereIn('analysis_status', ['completed', 'failed'])
                        ->where('analysis_updated_at', '>', now()->subDays(2))))
                ->orderByRaw("case analysis_status when 'processing' then 0 when 'queued' then 1 else 2 end")
                ->orderByDesc('analysis_updated_at')->limit(10)->get(),
            'queued' => Game::query()->where('club_id', $clubId)->whereIn('analysis_status', ['queued', 'processing'])->count(),
            'apiBase' => url('/api/v1'),
            'analyzerConfigured' => filled(config('services.analyzer.url')),
        ]);
    }
}
