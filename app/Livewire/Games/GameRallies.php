<?php

namespace App\Livewire\Games;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Livewire\Concerns\InteractsWithClub;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GameRally;
use App\Models\Player;
use App\Services\ActionIngestor;
use App\Stats\Report\Rally;
use App\Stats\Report\RallyBuilder;
use App\Support\Visibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Point by point" tab of the match page: every set, every rally (score before / after,
 * who served, who won and how), and inside a rally every touch in playing order,
 * from the serve on. Each touch seeks the video; coaches correct and add touches here.
 */
class GameRallies extends Component
{
    use InteractsWithClub;

    #[Locked]
    public Game $game;

    public function mount(Game $game): void
    {
        Gate::authorize('view', $game);
        $this->game = $game;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            // the match page coded something, or the AI sent new actions (it relays them)
            'stats-refreshed' => '$refresh',
        ];
    }

    /**
     * Correct one touch: who, what, how good, which rally; optionally take the player's time.
     */
    public function updateAction(int $actionId, ?int $playerId, string $skill, string $grade, ?int $set = null, ?int $rally = null, ?float $time = null): void
    {
        Gate::authorize('code', $this->game);

        $action = $this->game->actions()->findOrFail($actionId);
        $this->assertPlayer($playerId);

        $action->player_id = $playerId;
        $action->skill = Skill::from($skill);
        $action->grade = Grade::from($grade);

        if ($set !== null) {
            abort_unless($set >= 1 && $set <= 5, 422);
            $action->game_set_id = $this->game->sets()->firstOrCreate(['number' => $set])->id;
        }

        if ($rally !== null) {
            abort_unless($rally >= 1 && $rally <= 999, 422);
            $action->rally = $rally;
        }

        if ($time !== null && $time >= 0) {
            $action->video_time = round($time, 2);
        }

        // a person looked at it: the analyzer must not undo the correction
        if ($action->source === ActionSource::Ai) {
            $action->reviewed_at = now();
        }

        $action->needs_review = $action->needs_review && $action->player_id === null;
        $action->save();

        GameActionsRecorded::dispatch($this->game, 0, 1, 0, [$action->toBroadcast()]);
        $this->dispatch('rallies-changed');
    }

    /**
     * Add a touch the AI (or the coder) missed to a given rally.
     */
    public function addAction(int $set, int $rally, ?int $playerId, string $skill, string $grade, ?float $time = null): void
    {
        Gate::authorize('code', $this->game);
        abort_unless($set >= 1 && $set <= 5 && $rally >= 1 && $rally <= 999, 422);
        $this->assertPlayer($playerId);

        $result = app(ActionIngestor::class)->ingest($this->game, [[
            'player_id' => $playerId,
            'set' => $set,
            'rally' => $rally,
            'skill' => Skill::from($skill)->value,
            'grade' => Grade::from($grade)->value,
            'video_time' => $time !== null && $time >= 0 ? round($time, 2) : null,
            'needs_review' => false,
        ]], ActionSource::Manual, $this->user());

        if ($result->skipped !== []) {
            $this->toast(__('Player not found.'), 'danger');

            return;
        }

        $this->dispatch('rallies-changed');
    }

    public function deleteAction(int $actionId, ActionIngestor $ingestor): void
    {
        Gate::authorize('code', $this->game);

        $ingestor->delete($this->game->actions()->findOrFail($actionId));
        $this->dispatch('rallies-changed');
        $this->toast(__('Action deleted.'));
    }

    /**
     * Who won the rally: us / them, or null to go back to what the touches tell.
     */
    public function setWinner(int $set, int $rally, ?string $winner): void
    {
        Gate::authorize('code', $this->game);
        abort_unless($winner === null || in_array($winner, [GameRally::US, GameRally::THEM], true), 422);
        abort_unless($set >= 1 && $set <= 5 && $rally >= 1 && $rally <= 999, 422);

        $setModel = $this->game->sets()->firstOrCreate(['number' => $set]);
        $record = GameRally::query()->where('game_set_id', $setModel->id)->where('number', $rally)->first();

        if ($winner === null) {
            $record?->update(['winner' => null]);
        } else {
            GameRally::updateOrCreate(['game_set_id' => $setModel->id, 'number' => $rally], [
                'game_id' => $this->game->id,
                'winner' => $winner,
                'source' => ActionSource::Manual,
            ]);
        }

        $this->dispatch('rallies-changed');
    }

    /**
     * Who served the rally: us / them, or null to go back to what the touches tell.
     */
    public function setServer(int $set, int $rally, ?string $server): void
    {
        Gate::authorize('code', $this->game);
        abort_unless($server === null || in_array($server, [GameRally::US, GameRally::THEM], true), 422);
        abort_unless($set >= 1 && $set <= 5 && $rally >= 1 && $rally <= 999, 422);

        $setModel = $this->game->sets()->firstOrCreate(['number' => $set]);
        GameRally::updateOrCreate(['game_set_id' => $setModel->id, 'number' => $rally], [
            'game_id' => $this->game->id,
            'server' => $server,
            'source' => ActionSource::Manual,
        ]);

        $this->dispatch('rallies-changed');
    }

    /**
     * A new, empty rally at the end of a set, to fill with touches.
     */
    public function addRally(int $set): void
    {
        Gate::authorize('code', $this->game);
        abort_unless($set >= 1 && $set <= 5, 422);

        $setModel = $this->game->sets()->firstOrCreate(['number' => $set]);
        $last = max(
            (int) $this->game->actions()->where('game_set_id', $setModel->id)->max('rally'),
            (int) GameRally::query()->where('game_set_id', $setModel->id)->max('number'),
        );

        GameRally::create([
            'game_id' => $this->game->id,
            'game_set_id' => $setModel->id,
            'number' => $last + 1,
            'source' => ActionSource::Manual,
        ]);
    }

    public function render(RallyBuilder $builder): View
    {
        $game = $this->game->load(['sets.lineups', 'rallies']);
        $user = $this->user();
        $visible = Visibility::playerIdsFor($user, $game);
        $canCode = $user->can('code', $game);

        $actions = $game->actions()
            ->with(['player:id,number,first_name,last_name,nickname', 'set:id,number'])
            ->orderBy('id')
            ->get();

        // the score needs every touch; a parent only sees the touches of their children
        $shown = fn (GameAction $a) => $visible === null || in_array($a->player_id, $visible, true);
        $rallies = $builder->build($game, $actions);

        $sets = $game->sets->map(fn ($set) => [
            'number' => $set->number,
            'score_us' => $set->score_us,
            'score_them' => $set->score_them,
            'rallies' => $rallies->where('set', $set->number)->values()
                ->map(fn (Rally $r) => $this->describe($r, $shown))->all(),
        ])->filter(fn (array $s) => $s['rallies'] !== [] || $s['score_us'] + $s['score_them'] > 0)->values();

        $loose = $actions
            ->filter(fn (GameAction $a) => ($a->rally === null || $a->game_set_id === null) && $shown($a))
            ->sortBy(fn (GameAction $a) => [$a->video_time ?? INF, $a->id])
            ->values();

        return view('livewire.games.rallies', [
            'sets' => $sets,
            'loose' => $loose,
            'canCode' => $canCode,
            'roster' => $canCode
                ? Player::query()->forClub($game->club_id)->where('team_id', $game->team_id)
                    ->orderByRaw('number is null')->orderBy('number')->get()
                    ->map(fn (Player $p) => ['id' => $p->id, 'label' => trim($p->numberLabel().($p->isAnonymous() ? '' : ' '.$p->short_name))])
                    ->values()->all()
                : [],
            'skills' => collect(Skill::cases())->map(fn (Skill $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'grades' => collect(Grade::cases())->map(fn (Grade $g) => ['value' => $g->value, 'label' => $g->label()])->all(),
        ]);
    }

    /**
     * @param  callable(GameAction): bool  $shown
     * @return array<string, mixed>
     */
    private function describe(Rally $rally, callable $shown): array
    {
        $times = $rally->actions->pluck('video_time')->filter(fn ($t) => $t !== null);

        return [
            'set' => $rally->set,
            'number' => $rally->number,
            'server' => $rally->server,
            'winner' => $rally->winner,
            'rotation' => $rally->rotation,
            'before' => [$rally->scoreUs, $rally->scoreThem],
            'after' => [
                $rally->scoreUs + ($rally->winner === Rally::US ? 1 : 0),
                $rally->scoreThem + ($rally->winner === Rally::THEM ? 1 : 0),
            ],
            'start' => $times->min(),
            'end' => $times->max(),
            'outcome' => $this->outcome($rally),
            'hasServe' => $rally->first(Skill::Serve) !== null,
            'review' => $rally->actions->contains(fn (GameAction $a) => $a->needs_review),
            'untimed' => $rally->actions->contains(fn (GameAction $a) => $a->video_time === null),
            'actions' => $rally->actions->filter($shown)->values(),
            'hidden' => $rally->actions->reject($shown)->count(),
        ];
    }

    /** How the rally ended, in words. */
    private function outcome(Rally $rally): string
    {
        $last = $rally->actions->last(fn (GameAction $a) => in_array($a->grade, [Grade::Perfect, Grade::Error], true)
            || ($a->grade === Grade::Slash && in_array($a->skill, [Skill::Attack, Skill::Block], true)));
        $grade = $last?->grade;
        $skill = $last?->skill;

        if ($rally->winner === Rally::US) {
            return match (true) {
                $grade === Grade::Perfect && $skill === Skill::Serve => __('Ace'),
                $grade === Grade::Perfect && $skill === Skill::Attack => __('Attack kill'),
                $grade === Grade::Perfect && $skill === Skill::Block => __('Block point'),
                default => __('Opponent error or point'),
            };
        }

        if ($rally->winner === Rally::THEM) {
            return match (true) {
                $grade === Grade::Slash && $skill === Skill::Attack => __('Attack blocked'),
                $skill !== null && in_array($grade, [Grade::Error, Grade::Slash], true) => __('Our error').': '.$skill->label(),
                default => __('Opponent point'),
            };
        }

        return __('Outcome unknown');
    }

    private function assertPlayer(?int $playerId): void
    {
        abort_unless($playerId === null || Player::query()->forClub($this->game->club_id)->whereKey($playerId)->exists(), 422);
    }
}
