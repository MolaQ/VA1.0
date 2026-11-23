<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Models\Concerns\BelongsToClub;
use App\Support\YouTube;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single volleyball match. Named "Game" because `match` is a reserved word in PHP.
 *
 * @property int $id
 * @property int $team_id
 * @property string $opponent
 * @property Carbon $played_at
 * @property string|null $venue
 * @property bool $is_home
 * @property string|null $youtube_url
 * @property string|null $youtube_id
 * @property int $sets_won
 * @property int $sets_lost
 * @property AnalysisStatus $analysis_status
 * @property int $analysis_progress
 * @property string|null $analysis_message
 * @property Carbon|null $analysis_updated_at
 * @property-read Team $team
 */
#[Fillable([
    'club_id', 'team_id', 'opponent', 'played_at', 'venue', 'is_home', 'youtube_url', 'youtube_id',
    'sets_won', 'sets_lost', 'analysis_status', 'analysis_progress', 'analysis_message', 'analysis_updated_at',
])]
class Game extends Model
{
    use BelongsToClub;

    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
            'is_home' => 'boolean',
            'analysis_status' => AnalysisStatus::class,
            'analysis_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Game $game): void {
            if ($game->isDirty('youtube_url')) {
                $game->youtube_id = YouTube::extractId($game->youtube_url);
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<GameSet, $this>
     */
    public function sets(): HasMany
    {
        return $this->hasMany(GameSet::class)->orderBy('number');
    }

    /**
     * @return HasMany<GameAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }

    public function title(): string
    {
        $home = $this->team->name ?? __('Team');

        return $this->is_home ? "{$home} vs {$this->opponent}" : "{$this->opponent} vs {$home}";
    }

    public function result(): string
    {
        return "{$this->sets_won}:{$this->sets_lost}";
    }

    public function isWon(): ?bool
    {
        if ($this->sets_won === 0 && $this->sets_lost === 0) {
            return null;
        }

        return $this->sets_won > $this->sets_lost;
    }

    public function channelName(): string
    {
        return 'games.'.$this->id;
    }

    /**
     * Recalculate the match result from stored set scores.
     */
    public function refreshResult(): void
    {
        $won = 0;
        $lost = 0;

        foreach ($this->sets()->get() as $set) {
            if ($set->score_us === $set->score_them) {
                continue;
            }

            $set->score_us > $set->score_them ? $won++ : $lost++;
        }

        $this->forceFill(['sets_won' => $won, 'sets_lost' => $lost])->save();
    }
}
