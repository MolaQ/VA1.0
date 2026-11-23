<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who played in a set: starting rotation position (1-6), or a substitute.
 *
 * @property int $id
 * @property int $game_set_id
 * @property int $player_id
 * @property int|null $rotation_position
 * @property bool $is_substitute
 */
#[Fillable(['game_set_id', 'player_id', 'rotation_position', 'is_substitute'])]
class SetLineup extends Model
{
    protected function casts(): array
    {
        return ['is_substitute' => 'boolean'];
    }

    /**
     * @return BelongsTo<GameSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(GameSet::class, 'game_set_id');
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
