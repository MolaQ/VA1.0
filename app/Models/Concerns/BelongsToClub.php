<?php

namespace App\Models\Concerns;

use App\Models\Club;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared tenancy helpers for every model that lives inside a club.
 *
 * @property int $club_id
 * @property-read Club $club
 */
trait BelongsToClub
{
    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForClub(Builder $query, Club|int $club): void
    {
        $query->where($this->qualifyColumn('club_id'), $club instanceof Club ? $club->getKey() : $club);
    }
}
