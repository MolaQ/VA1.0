<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateClub
{
    /**
     * Create a new club (tenant) with its owner as administrator and a first team.
     */
    public function handle(User $owner, string $name, ?string $city = null, ?string $teamName = null): Club
    {
        return DB::transaction(function () use ($owner, $name, $city, $teamName) {
            $club = Club::create(['name' => $name, 'city' => $city]);
            $club->addMember($owner, Role::Admin);
            $club->teams()->create(['name' => $teamName ?: __('Seniors'), 'season' => self::currentSeason()]);
            $owner->switchClub($club);
            $owner->flushRoleCache();

            return $club;
        });
    }

    public static function currentSeason(): string
    {
        $year = (int) now()->format('Y');
        $start = (int) now()->format('n') >= 8 ? $year : $year - 1;

        return $start.'/'.($start + 1);
    }
}
