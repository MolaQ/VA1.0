<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\PageView;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Synthetic page views for the last 30 days so the analytics dashboard has data.
 */
class AnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(77);

        $club = Club::query()->orderBy('id')->firstOrFail();
        $users = User::query()->whereHas('memberships', fn ($q) => $q->where('club_id', $club->id))->pluck('id')->all();

        $pages = ['/dashboard' => 30, '/games' => 18, '/games/1' => 25, '/games/2' => 10, '/players' => 12, '/players/2' => 6, '/profile' => 3];
        $refs = [null => 60, 'google.com' => 15, 'facebook.com' => 12, 'youtube.com' => 8, 'instagram.com' => 5];
        $devices = [['mobile', 'Chrome', 'Android'], ['mobile', 'Safari', 'iOS'], ['desktop', 'Chrome', 'Windows'], ['desktop', 'Safari', 'macOS'], ['tablet', 'Safari', 'iOS'], ['tv', 'Chrome', 'Smart TV']];
        $rows = [];

        for ($day = 29; $day >= 0; $day--) {
            $visits = mt_rand(12, 40) + (now()->subDays($day)->isWeekend() ? 25 : 0);

            for ($v = 0; $v < $visits; $v++) {
                $at = now()->subDays($day)->startOfDay()->addMinutes(mt_rand(7 * 60, 23 * 60));

                if ($at->isFuture()) {
                    continue;
                }

                $hash = hash('sha256', "demo-{$day}-{$v}");
                $device = $devices[array_rand($devices)];
                $ref = $this->pick($refs);
                $views = mt_rand(1, 100) <= 35 ? 1 : mt_rand(2, 7);

                for ($i = 0; $i < $views; $i++) {
                    $rows[] = [
                        'club_id' => $club->id,
                        'user_id' => $users ? $users[array_rand($users)] : null,
                        'visitor_hash' => $hash,
                        'path' => $this->pick($pages),
                        'route' => null,
                        'referrer_host' => $i === 0 ? $ref : null,
                        'utm_source' => null,
                        'device' => $device[0],
                        'browser' => $device[1],
                        'os' => $device[2],
                        'country' => $this->pick(['PL' => 80, 'IT' => 10, 'DE' => 5, 'GB' => 5]),
                        'locale' => $this->pick(['pl' => 80, 'en' => 10, 'it' => 10]),
                        'is_pwa' => $device[0] === 'mobile' && mt_rand(1, 100) <= 40,
                        'is_entry' => $i === 0,
                        'engaged_seconds' => mt_rand(5, 240),
                        'viewed_at' => $at->copy()->addSeconds($i * mt_rand(20, 200)),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PageView::insert($chunk);
        }
    }

    /**
     * @template T of array-key
     *
     * @param  array<T, int>  $weights
     * @return T|null
     */
    private function pick(array $weights): mixed
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
