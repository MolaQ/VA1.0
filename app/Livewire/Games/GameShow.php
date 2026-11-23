<?php

namespace App\Livewire\Games;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Jobs\RequestVideoAnalysis;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GameRally;
use App\Models\Player;
use App\Services\ActionIngestor;
use App\Stats\GameStatsCalculator;
use App\Support\Visibility;
use App\Support\YouTube;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Match screen: YouTube player + live box score. Every number in the table is
 * clickable and plays the matching moments of the video.
 */
class GameShow extends Component
{
    use InteractsWithClub;

    #[Locked]
    public Game $game;

    /** null = whole match */
    #[Url(as: 'set')]
    public ?int $setNumber = null;

    /** Set currently being coded manually */
    public int $codingSet = 1;

    public int $rally = 1;

    public function mount(Game $game): void
    {
        abort_unless($game->club_id === $this->club()->id || $this->user()->is_super_admin, 404);
        Gate::authorize('view', $game);

        $this->game = $game;
        $this->codingSet = max(1, (int) $game->sets()->max('number'));
        $this->rally = (int) $game->actions()->where('game_set_id', $game->sets()->where('number', $this->codingSet)->value('id'))->max('rally') + 1;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:games.'.$this->game->id.',.actions.recorded' => 'onActionsRecorded',
            'echo-private:games.'.$this->game->id.',.analysis.updated' => 'onAnalysisUpdated',
        ];
    }

    public function onActionsRecorded(): void
    {
        $this->dispatch('stats-refreshed');
    }

    public function onAnalysisUpdated(): void
    {
        $this->game->refresh();
    }

    /**
     * Video moments behind a stat cell, consumed by the player playlist (JS).
     *
     * @return array<int, array{t: float, label: string}>
     */
    public function clips(?int $playerId, string $skill, string $filter = 'all'): array
    {
        $visible = Visibility::playerIdsFor($this->user(), $this->game);

        if ($visible !== null && ($playerId === null || ! in_array($playerId, $visible, true))) {
            return [];
        }

        $query = $this->game->actions()
            ->with(['player:id,number,first_name,last_name,nickname', 'set:id,number'])
            ->whereNotNull('video_time')
            ->when($playerId, fn ($q) => $q->where('player_id', $playerId), fn ($q) => $q->whereNotNull('player_id'))
            ->when($this->setNumber, fn ($q) => $q->whereHas('set', fn ($s) => $s->where('number', $this->setNumber)));

        if ($skill === 'points') {
            $query->where('grade', Grade::Perfect->value)
                ->whereIn('skill', [Skill::Serve->value, Skill::Attack->value, Skill::Block->value]);
        } else {
            $query->where('skill', Skill::from($skill)->value);

            $grades = match ($filter) {
                'all' => null,
                'pos' => ['#', '+'],
                'touch' => ['+', '!', '-', '/'],
                'plus' => ['#', '/'],
                default => [Grade::from($filter)->value],
            };

            $query->when($grades, fn ($q) => $q->whereIn('grade', $grades));
        }

        return $query->orderBy('video_time')->limit(200)->get()
            ->map(fn (GameAction $a) => [
                't' => (float) $a->video_time,
                'label' => trim(($a->player ? trim($a->player->numberLabel().' '.($a->player->isAnonymous() ? '' : $a->player->short_name)) : '').' · '.$a->skill->label().' '.$a->grade->value.($a->set ? ' · S'.$a->set->number : '')),
            ])->all();
    }

    /**
     * Coding a jersey number that isn't in the roster yet: add it to the match team as "#47".
     */
    public function addNumber(int $number): ?int
    {
        Gate::authorize('code', $this->game);

        if ($number < 0 || $number > 99) {
            return null;
        }

        $player = Player::query()->forClub($this->game->club_id)
            ->where('team_id', $this->game->team_id)->where('number', $number)->first()
            ?? Player::create(['club_id' => $this->game->club_id, 'team_id' => $this->game->team_id, 'number' => $number]);

        if (! $player->is_active) {
            $player->update(['is_active' => true]);
        }

        return $player->id;
    }

    /**
     * Manual coding (fallback / correction of the AI): one tap (or keystroke) per touch.
     * Zones are optional: attack from / to, serve target.
     */
    public function record(int $playerId, string $skill, string $grade, ?float $time, ?int $zoneFrom = null, ?int $zoneTo = null): void
    {
        Gate::authorize('code', $this->game);

        $zone = fn (?int $z) => $z !== null && $z >= 1 && $z <= 9 ? $z : null;

        $result = app(ActionIngestor::class)->ingest($this->game, [[
            'player_id' => $playerId,
            'set' => $this->codingSet,
            'rally' => $this->rally,
            'skill' => Skill::from($skill)->value,
            'grade' => Grade::from($grade)->value,
            'video_time' => $time !== null ? round(max(0, $time), 2) : null,
            'zone_from' => $zone($zoneFrom),
            'zone_to' => $zone($zoneTo),
        ]], ActionSource::Manual, $this->user());

        if ($result->skipped !== []) {
            $this->toast(__('Player not found.'), 'danger');

            return;
        }

        // A point-ending touch closes the rally.
        if (in_array($grade, ['#', '='], true) && $skill !== Skill::Reception->value) {
            $this->rally++;
        }
    }

    /**
     * Close the current rally with an explicit winner: for points our touches don't explain
     * (opponent error, opponent kill or ace).
     */
    public function point(string $winner, ?float $time = null): void
    {
        Gate::authorize('code', $this->game);
        abort_unless(in_array($winner, [GameRally::US, GameRally::THEM], true), 422);

        $set = $this->game->sets()->firstOrCreate(['number' => $this->codingSet]);
        GameRally::updateOrCreate(['game_set_id' => $set->id, 'number' => $this->rally], [
            'game_id' => $this->game->id,
            'winner' => $winner,
            'video_time' => $time !== null ? round(max(0, $time), 2) : null,
            'source' => ActionSource::Manual,
        ]);

        $this->rally++;
        $this->dispatch('stats-refreshed');
    }

    public function nextRally(): void
    {
        $this->rally++;
    }

    public function updatedCodingSet(): void
    {
        $setId = $this->game->sets()->where('number', $this->codingSet)->value('id');
        $this->rally = (int) $this->game->actions()->where('game_set_id', $setId)->max('rally') + 1;
    }

    public function deleteAction(int $actionId, ActionIngestor $ingestor): void
    {
        Gate::authorize('code', $this->game);

        $action = $this->game->actions()->findOrFail($actionId);
        $ingestor->delete($action);
        $this->toast(__('Action deleted.'));
    }

    public function saveSetScore(int $number, int $us, int $them): void
    {
        Gate::authorize('update', $this->game);

        $this->game->sets()->updateOrCreate(['number' => $number], [
            'score_us' => max(0, min(99, $us)),
            'score_them' => max(0, min(99, $them)),
        ]);
        $this->game->refreshResult();
        $this->toast(__('Set score saved.'));
    }

    /**
     * Starting rotation of one set (position 1 = serves first for us) and who served first.
     *
     * @param  array<int|string, int|string|null>  $positions  rotation position (1-6) => player id
     */
    public function saveLineup(int $number, array $positions, ?string $firstServe = null): void
    {
        Gate::authorize('update', $this->game);
        abort_unless($number >= 1 && $number <= 5, 404);

        $roster = Player::query()->forClub($this->game->club_id)->pluck('id')->all();
        $set = $this->game->sets()->firstOrCreate(['number' => $number]);
        $set->update(['we_serve_first' => match ($firstServe) {
            'us' => true,
            'them' => false,
            default => null,
        }]);

        $chosen = collect($positions)
            ->mapWithKeys(fn ($playerId, $pos) => [(int) $pos => (int) $playerId])
            ->filter(fn (int $playerId, int $pos) => $pos >= 1 && $pos <= 6 && in_array($playerId, $roster, true));

        if ($chosen->duplicates()->isNotEmpty()) {
            $this->toast(__('A player can only be in one position.'), 'error');

            return;
        }

        $set->lineups()->whereNotNull('rotation_position')->delete();
        foreach ($chosen as $pos => $playerId) {
            $set->lineups()->updateOrCreate(['player_id' => $playerId], ['rotation_position' => $pos, 'is_substitute' => false]);
        }
        $this->toast(__('Lineup saved.'));
    }

    public function reanalyze(): void
    {
        Gate::authorize('update', $this->game);

        RequestVideoAnalysis::dispatch($this->game);
        $this->toast(__('AI analysis has been requested.'), 'info');
    }

    public function render(GameStatsCalculator $calculator): View
    {
        $game = $this->game->load(['team', 'sets.lineups']);
        $user = $this->user();
        $visible = Visibility::playerIdsFor($user, $game);
        $canCode = $user->can('code', $game);

        return view('livewire.games.show', [
            'stats' => $calculator->forGame($game, $this->setNumber, $visible),
            'showTotals' => $visible === null,
            'canCode' => $canCode,
            'canEdit' => $user->can('update', $game),
            'roster' => $canCode || $user->can('update', $game)
                ? Player::query()->forClub($game->club_id)->where('team_id', $game->team_id)->where('is_active', true)->orderBy('number')->get()
                : collect(),
            'timeline' => $game->actions()
                ->with(['player:id,number,first_name,last_name,nickname', 'set:id,number'])
                ->when($visible !== null, fn ($q) => $q->whereIn('player_id', $visible))
                ->latest('id')->limit(40)->get(),
            'skills' => [Skill::Serve, Skill::Reception, Skill::Attack, Skill::Block, Skill::Dig, Skill::Set],
            'grades' => Grade::cases(),
        ])->title($game->title());
    }

    public static function time(?float $seconds): string
    {
        return YouTube::formatTime($seconds);
    }
}
