<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $club_id
 * @property int|null $user_id
 * @property string $visitor_hash
 * @property string $path
 * @property string|null $route
 * @property string|null $referrer_host
 * @property string|null $utm_source
 * @property string $device
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $country
 * @property string|null $locale
 * @property bool $is_pwa
 * @property bool $is_entry
 * @property int $engaged_seconds
 * @property Carbon $viewed_at
 */
#[Fillable([
    'club_id', 'user_id', 'visitor_hash', 'path', 'route', 'referrer_host', 'utm_source', 'device',
    'browser', 'os', 'country', 'locale', 'is_pwa', 'is_entry', 'engaged_seconds', 'viewed_at',
])]
class PageView extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('viewed_at', '<', now()->subDays((int) config('analytics.retention_days', 400)));
    }

    protected function casts(): array
    {
        return [
            'is_pwa' => 'boolean',
            'is_entry' => 'boolean',
            'viewed_at' => 'datetime',
        ];
    }
}
