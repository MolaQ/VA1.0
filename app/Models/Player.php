<?php

namespace App\Models;

use App\Enums\Position;
use App\Models\Concerns\BelongsToClub;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $team_id
 * @property int|null $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $nickname
 * @property int|null $number
 * @property Position|null $position
 * @property Carbon|null $birth_date
 * @property bool $is_active
 * @property-read string $full_name Name, else nickname, else "#47".
 * @property-read string $short_name Nickname, else last name, else "#47".
 * @property-read Team|null $team
 * @property-read User|null $user
 */
#[Fillable(['club_id', 'team_id', 'user_id', 'first_name', 'last_name', 'nickname', 'number', 'position', 'birth_date', 'is_active'])]
class Player extends Model
{
    use BelongsToClub;

    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'position' => Position::class,
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Display name: real name when known, otherwise the nickname, otherwise the jersey number.
     */
    public function getFullNameAttribute(): string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name !== '' ? $name : ($this->nickname ?: $this->numberLabel());
    }

    /**
     * Compact label for timelines and clips.
     */
    public function getShortNameAttribute(): string
    {
        return $this->nickname ?: ($this->last_name ?: ($this->first_name ?: $this->numberLabel()));
    }

    public function numberLabel(): string
    {
        return $this->number !== null ? '#'.$this->number : '—';
    }

    /**
     * True when the player is known only by the jersey number.
     */
    public function isAnonymous(): bool
    {
        return blank($this->first_name) && blank($this->last_name) && blank($this->nickname);
    }

    public function initials(): string
    {
        if ($this->isAnonymous()) {
            return (string) ($this->number ?? '?');
        }

        $source = trim($this->first_name.' '.$this->last_name) ?: (string) $this->nickname;
        $parts = preg_split('/\s+/', $source) ?: [];

        return Str::upper(Str::substr($parts[0] ?? '', 0, 1).Str::substr($parts[1] ?? '', 0, 1));
    }

    public function isLibero(): bool
    {
        return $this->position === Position::Libero;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The player's own login account (optional).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parents / guardians allowed to see this player's data.
     *
     * @return BelongsToMany<User, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_player')->withTimestamps();
    }

    /**
     * @return HasMany<GameAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }
}
