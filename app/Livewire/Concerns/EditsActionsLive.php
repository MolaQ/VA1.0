<?php

namespace App\Livewire\Concerns;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Gate;

/**
 * Live correction of one touch: every change is saved at once (no save button) and confirmed
 * with a toast. Used by the match page lists (timeline, coding, point by point).
 *
 * @property Game $game
 */
trait EditsActionsLive
{
    /**
     * @param  string  $field  player | skill | grade | set | rally | time
     * @param  string|null  $value  as the input gives it ('' clears player, rally and time)
     */
    public function patchAction(int $actionId, string $field, ?string $value = null): void
    {
        Gate::authorize('code', $this->game);

        $action = $this->game->actions()->findOrFail($actionId);
        $value = $value === '' ? null : $value;

        switch ($field) {
            case 'player':
                $id = $value === null ? null : (int) $value;
                abort_unless($id === null || Player::query()->forClub($this->game->club_id)->whereKey($id)->exists(), 422);
                $action->player_id = $id;
                break;
            case 'skill':
                $action->skill = Skill::tryFrom((string) $value) ?? abort(422);
                break;
            case 'grade':
                $action->grade = Grade::tryFrom((string) $value) ?? abort(422);
                break;
            case 'set':
                $set = (int) $value;
                abort_unless($set >= 1 && $set <= 5, 422);
                $action->game_set_id = $this->game->sets()->firstOrCreate(['number' => $set])->id;
                break;
            case 'rally':
                $rally = $value === null ? null : (int) $value;
                abort_unless($rally === null || ($rally >= 1 && $rally <= 999), 422);
                $action->rally = $rally;
                break;
            case 'time':
                abort_unless($value === null || (is_numeric($value) && (float) $value >= 0), 422);
                $action->video_time = $value === null ? null : round((float) $value, 2);
                break;
            default:
                abort(422);
        }

        // a person looked at it: the analyzer must not undo the correction
        if ($action->source === ActionSource::Ai) {
            $action->fill(['reviewed_at' => now()]);
        }

        $action->needs_review = $action->needs_review && $action->player_id === null;
        $action->save();
        $action->load('player');

        GameActionsRecorded::dispatch($this->game, 0, 1, 0, [$action->toBroadcast()]);
        $this->actionsChanged();
        $this->toast(__('Saved').': '.trim(($action->player?->numberLabel() ?? '?').' · '.$action->skill->label().' '.$action->grade->value));
    }

    /** Let the other lists on the page refresh. */
    abstract protected function actionsChanged(): void;
}
