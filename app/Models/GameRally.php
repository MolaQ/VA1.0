<?php

namespace App\Models;

use App\Enums\ActionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit outcome of one rally ("us" / "them"), when the actions alone don't tell.
 *
 * @property int $id
 * @property int $game_id
 * @property int $game_set_id
 * @property int $number
 * @property string|null $server
 * @property string|null $winner
 * @property float|null $video_time
 * @property ActionSource $source
 */
#[Fillable(['game_id', 'game_set_id', 'number', 'server', 'winner', 'video_time', 'source'])]
class GameRally extends Model
{
    public const US = 'us';

    public const THEM = 'them';

    protected function casts(): array
    {
        return ['video_time' => 'float', 'source' => ActionSource::class];
    }

    /**
     * @return BelongsTo<GameSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(GameSet::class, 'game_set_id');
    }
}
