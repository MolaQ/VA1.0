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
 * @property bool|null $we_serve_first
 * @property string|null $our_side near | far: the half of the camera picture we defend
 * @property-read Game $game
 */
#[Fillable(['game_id', 'number', 'score_us', 'score_them', 'video_start', 'we_serve_first', 'our_side'])]
class GameSet extends Model
{
    protected function casts(): array
    {
        return ['video_start' => 'float', 'we_serve_first' => 'boolean'];
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

    /**
     * @return HasMany<GameRally, $this>
     */
    public function rallies(): HasMany
    {
        return $this->hasMany(GameRally::class);
    }
}
