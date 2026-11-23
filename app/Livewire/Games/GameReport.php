<?php

namespace App\Livewire\Games;

use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Stats\Report\MatchReportBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Match report: efficiency & success per skill / player / set, game and tactics
 * (side-out, break point, rotations, zones), setting distribution and player usefulness.
 * Printable ("Save as PDF" from the browser).
 */
class GameReport extends Component
{
    use InteractsWithClub;

    #[Locked]
    public Game $game;

    /** null = whole match */
    #[Url(as: 'set')]
    public ?int $setNumber = null;

    public function mount(Game $game): void
    {
        abort_unless($game->club_id === $this->club()->id || $this->user()->is_super_admin, 404);
        Gate::authorize('report', $game);

        $this->game = $game;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:games.'.$this->game->id.',.actions.recorded' => '$refresh',
        ];
    }

    public function render(MatchReportBuilder $builder): View
    {
        $game = $this->game->load(['team', 'sets.lineups', 'rallies']);

        return view('livewire.games.report', [
            'report' => $builder->build($game, $this->setNumber),
        ])->title(__('Report').' · '.$game->title());
    }
}
