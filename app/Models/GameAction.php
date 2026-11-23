<?php

namespace App\Models;

use App\Enums\ActionSource;
use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\Concerns\BelongsToClub;
use Database\Factories\GameActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scouted touch of the ball: who, what skill, how good, and when in the video.
 *
 * @property int $id
 * @property int $game_id
 * @property int|null $game_set_id
 * @property int|null $player_id
 * @property int|null $rally
 * @property Skill $skill
 * @property Grade $grade
 * @property float|null $video_time
 * @property int|null $zone_from
 * @property int|null $zone_to
 * @property ActionSource $source
 * @property float|null $confidence
 * @property string|null $external_id
 * @property array<string, mixed>|null $meta
 * @property int|null $created_by
 * @property-read Game $game
 * @property-read GameSet|null $set
 * @property-read Player|null $player
 */
#[Fillable([
    'club_id', 'game_id', 'game_set_id', 'player_id', 'rally', 'skill', 'grade', 'video_time',
    'zone_from', 'zone_to', 'source', 'confidence', 'external_id', 'meta', 'created_by',
])]
class GameAction extends Model
{
    use BelongsToClub;

    /** @use HasFactory<GameActionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'skill' => Skill::class,
            'grade' => Grade::class,
            'source' => ActionSource::class,
            'video_time' => 'float',
            'confidence' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
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

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            'set' => $this->set?->number,
            'skill' => $this->skill->value,
            'grade' => $this->grade->value,
            'video_time' => $this->video_time,
            'source' => $this->source->value,
        ];
    }
}
