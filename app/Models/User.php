<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property string|null $locale
 * @property string|null $theme
 * @property int|null $current_club_id
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Club|null $currentClub
 */
#[Fillable(['name', 'email', 'password', 'current_club_id', 'locale', 'theme'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var array<int, Collection<int, Role>>
     */
    private array $roleCache = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<Club, $this>
     */
    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'memberships')->distinct();
    }

    public function clubCount(): int
    {
        return $this->memberships()->distinct()->count('club_id');
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function currentClub(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'current_club_id');
    }

    /**
     * Player profiles linked to this account (the user *is* the player).
     *
     * @return HasMany<Player, $this>
     */
    public function playerProfiles(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Players this user is a parent / guardian of.
     *
     * @return BelongsToMany<Player, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'guardian_player')->withTimestamps();
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesIn(Club|int|null $club): Collection
    {
        if ($club === null) {
            return collect();
        }

        $clubId = $club instanceof Club ? $club->id : $club;

        return $this->roleCache[$clubId] ??= $this->memberships()
            ->where('club_id', $clubId)
            ->pluck('role')
            ->map(fn ($role) => $role instanceof Role ? $role : Role::from($role))
            ->values();
    }

    public function hasRole(Club|int|null $club, Role ...$roles): bool
    {
        $held = $this->rolesIn($club);

        foreach ($roles as $role) {
            if ($held->contains($role)) {
                return true;
            }
        }

        return false;
    }

    public function belongsToClub(Club|int|null $club): bool
    {
        return $this->is_super_admin || $this->rolesIn($club)->isNotEmpty();
    }

    public function isStaffOf(Club|int|null $club): bool
    {
        return $this->is_super_admin || $this->hasRole($club, Role::Admin, Role::Trainer);
    }

    public function isAdminOf(Club|int|null $club): bool
    {
        return $this->is_super_admin || $this->hasRole($club, Role::Admin);
    }

    /**
     * Primary role in the current club, for display purposes.
     */
    public function primaryRole(?Club $club = null): ?Role
    {
        $roles = $this->rolesIn($club ?? $this->current_club_id);

        foreach (Role::cases() as $role) {
            if ($roles->contains($role)) {
                return $role;
            }
        }

        return null;
    }

    public function switchClub(Club $club): void
    {
        $this->forceFill(['current_club_id' => $club->id])->save();
        $this->setRelation('currentClub', $club);
    }

    public function flushRoleCache(): void
    {
        $this->roleCache = [];
    }
}
