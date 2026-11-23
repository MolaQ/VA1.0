<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToClub;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property Role $role
 * @property-read User $user
 */
#[Fillable(['club_id', 'user_id', 'role'])]
class Membership extends Model
{
    use BelongsToClub;

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
