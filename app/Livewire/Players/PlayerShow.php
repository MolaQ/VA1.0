<?php

namespace App\Livewire\Players;

use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Models\Player;
use App\Stats\GameStatsCalculator;
use App\Stats\PlayerStatLine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlayerShow extends Component
{
    use InteractsWithClub;

    #[Locked]
    public Player $player;

    public function mount(Player $player): void
    {
        abort_unless($player->club_id === $this->club()->id || $this->user()->is_super_admin, 404);
        Gate::authorize('view', $player);

        $this->player = $player;
    }

    public function render(GameStatsCalculator $calculator): View
    {
        $player = $this->player->load('team');

        $games = Game::query()->forClub($player->club_id)
            ->where(fn ($q) => $q->where('team_id', $player->team_id)
                ->orWhereHas('actions', fn ($a) => $a->where('player_id', $player->id)))
            ->with('team')
            ->latest('played_at')
            ->limit(20)
            ->get();

        $season = new PlayerStatLine($player);
        $perGame = $games->map(function (Game $game) use ($calculator, $player, $season) {
            $line = $calculator->forGame($game, null, [$player->id])->forPlayer($player->id) ?? new PlayerStatLine($player);
            $season->merge($line);

            return ['game' => $game, 'line' => $line];
        })->filter(fn ($row) => $row['line']->hasPlayed())->values();

        return view('livewire.players.show', [
            'season' => $season,
            'perGame' => $perGame,
            'maxPoints' => max(1, (int) $perGame->max(fn ($r) => $r['line']->points)),
        ])->title($player->full_name);
    }
}
