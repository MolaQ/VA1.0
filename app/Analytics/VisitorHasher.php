<?php

namespace App\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Builds an anonymous visitor id without cookies (GDPR friendly):
 * sha256(daily salt + app key + IP + user agent). The salt is random, kept
 * only in cache and replaced every day, so a hash cannot be reversed or
 * linked to the same person on another day.
 */
final class VisitorHasher
{
    public function hash(string $ip, string $userAgent): string
    {
        return hash('sha256', $this->salt().'|'.config('app.key').'|'.$ip.'|'.$userAgent);
    }

    public function salt(): string
    {
        $day = now()->toDateString();

        return Cache::remember("analytics:salt:{$day}", now()->endOfDay()->addHour(), fn () => Str::random(64));
    }
}
