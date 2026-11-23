<?php

namespace App\Http\Middleware;

use App\Models\Club;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes sure an authenticated user always works inside a club they belong to
 * (multi-tenancy). Falls back to their first membership; users without any
 * club are sent to onboarding.
 */
class EnsureClubContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $club = $user->currentClub;

        if ($club === null || ! $user->belongsToClub($club)) {
            $club = $user->clubs()->orderBy('clubs.name')->first()
                ?? ($user->is_super_admin ? Club::query()->orderBy('name')->first() : null);

            if ($club === null) {
                return $request->routeIs('onboarding') ? $next($request) : redirect()->route('onboarding');
            }

            $user->switchClub($club);
        }

        app()->instance('currentClub', $club);

        return $next($request);
    }
}
