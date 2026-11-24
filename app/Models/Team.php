<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClub;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string|null $season
 */
#[Fillable(['club_id', 'name', 'category', 'season'])]
class Team extends Model
{
    use BelongsToClub;

    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
