<?php

namespace App\Livewire\Games;

use App\Enums\AnalysisStatus;
use App\Jobs\RequestVideoAnalysis;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Support\YouTube;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GameForm extends Component
{
    use InteractsWithClub;

    public ?Game $game = null;

    public ?int $team_id = null;

    public string $opponent = '';

    public string $played_at = '';

    public string $venue = '';

    public bool $is_home = true;

    public string $youtube_url = '';

    public bool $analyze = true;

    public function mount(?Game $game = null): void
    {
        $club = $this->club();

        if ($game?->exists) {
            abort_unless($game->club_id === $club->id, 404);
            Gate::authorize('update', $game);

            $this->game = $game;
            $this->fill($game->only(['team_id', 'opponent', 'is_home']));
            $this->venue = (string) $game->venue;
            $this->youtube_url = (string) $game->youtube_url;
            $this->played_at = $game->played_at->format('Y-m-d\TH:i');
            $this->analyze = false;
        } else {
            Gate::authorize('create', [Game::class, $club]);
            $this->team_id = $club->teams()->value('id');
            $this->played_at = now()->startOfHour()->format('Y-m-d\TH:i');
        }
    }

    #[Computed]
    public function previewId(): ?string
    {
        return YouTube::extractId($this->youtube_url);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'team_id' => ['required', Rule::exists('teams', 'id')->where('club_id', $this->club()->id)],
            'opponent' => ['required', 'string', 'max:120'],
            'played_at' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
            'is_home' => ['boolean'],
            'youtube_url' => ['nullable', 'string', 'max:255', function (string $attr, mixed $value, Closure $fail) {
                if (filled($value) && YouTube::extractId((string) $value) === null) {
                    $fail(__('This doesn\'t look like a YouTube video link.'));
                }
            }],
            'analyze' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $club = $this->club();

        $attributes = [
            'team_id' => $data['team_id'],
            'opponent' => $data['opponent'],
            'played_at' => $data['played_at'],
            'venue' => $data['venue'] ?: null,
            'is_home' => $data['is_home'],
            'youtube_url' => $data['youtube_url'] ?: null,
        ];

        if ($this->game) {
            $videoChanged = $this->game->youtube_url !== $attributes['youtube_url'];
            $this->game->update($attributes);
            $game = $this->game;
        } else {
            $videoChanged = true;
            $game = $club->games()->create($attributes + ['analysis_status' => AnalysisStatus::Pending]);
        }

        if ($data['analyze'] && $videoChanged && $game->youtube_id) {
            RequestVideoAnalysis::dispatch($game);
        }

        session()->flash('toast', __('Match saved.'));
        $this->redirectRoute('games.show', $game, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.games.form', [
            'teams' => $this->club()->teams()->orderBy('name')->get(),
        ])->title($this->game ? __('Edit match') : __('New match'));
    }
}
