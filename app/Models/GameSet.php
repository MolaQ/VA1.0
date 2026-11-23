<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $game_id
 * @property int $number
 * @property int $score_us
 * @property int $score_them
 * @property float|null $video_start
 * @property-read Game $game
 */
#[Fillable(['game_id', 'number', 'score_us', 'score_them', 'video_start'])]
class GameSet extends Model
{
    protected function casts(): array
    {
        return ['video_start' => 'float'];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<SetLineup, $this>
     */
    public function lineups(): HasMany
    {
        return $this->hasMany(SetLineup::class);
    }
}
