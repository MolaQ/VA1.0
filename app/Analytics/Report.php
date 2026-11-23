<?php

namespace App\Analytics;

use App\Models\PageView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aggregates cookie-less page views into the classic web-analytics metrics.
 *
 *  - visitors:  distinct daily visitor hashes (summed per day: the salt rotates daily by design)
 *  - visits:    sessions; a new one starts after 30 min of inactivity
 *  - bounce:    visits with a single page view
 *  - duration:  time between first and last view of a visit + engaged time on the last page
 */
final class Report
{
    public function __construct(
        private readonly ?int $clubId,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public static function forPeriod(?int $clubId, string $period): self
    {
        $now = CarbonImmutable::now();

        $from = match ($period) {
            'today' => $now->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            '90d' => $now->subDays(89)->startOfDay(),
            default => $now->subDays(6)->startOfDay(),
        };

        return new self($clubId, $from, $now);
    }

    /**
     * @return Builder<PageView>
     */
    private function query(): Builder
    {
        return PageView::query()
            ->when($this->clubId !== null, fn ($q) => $q->where('club_id', $this->clubId))
            ->whereBetween('viewed_at', [$this->from, $this->to]);
    }

    /**
     * @return array{pageviews: int, visitors: int, visits: int, bounce_rate: float|null, avg_duration: int, views_per_visit: float|null, pwa_share: float|null}
     */
    public function summary(): array
    {
        $visits = $this->visits();
        $pageviews = (int) $this->query()->count();
        $count = $visits->count();

        return [
            'pageviews' => $pageviews,
            'visitors' => (int) $this->query()
                ->selectRaw('count(distinct visitor_hash) as c')
                ->value('c'),
            'visits' => $count,
            'bounce_rate' => $count > 0 ? $visits->where('views', 1)->count() / $count : null,
            'avg_duration' => $count > 0 ? (int) round($visits->avg('duration')) : 0,
            'views_per_visit' => $count > 0 ? round($pageviews / $count, 1) : null,
            'pwa_share' => $pageviews > 0 ? $this->query()->where('is_pwa', true)->count() / $pageviews : null,
        ];
    }

    /**
     * @return Collection<int, array{views: int, duration: int}>
     */
    private function visits(): Collection
    {
        $visits = collect();
        $open = [];

        $this->query()
            ->orderBy('visitor_hash')->orderBy('viewed_at')
            ->select(['visitor_hash', 'viewed_at', 'engaged_seconds'])
            ->lazy(2000)
            ->each(function (PageView $v) use (&$open, $visits) {
                $key = $v->visitor_hash;
                $at = $v->viewed_at->getTimestamp();

                if (isset($open[$key]) && $at - $open[$key]['last'] <= Tracker::SESSION_GAP_MINUTES * 60) {
                    $open[$key]['views']++;
                    $open[$key]['last'] = $at;
                    $open[$key]['tail'] = $v->engaged_seconds;

                    return;
                }

                if (isset($open[$key])) {
                    $visits->push($this->close($open[$key]));
                }

                $open[$key] = ['first' => $at, 'last' => $at, 'views' => 1, 'tail' => $v->engaged_seconds];
            });

        foreach ($open as $visit) {
            $visits->push($this->close($visit));
        }

        return $visits;
    }

    /**
     * @param  array{first: int, last: int, views: int, tail: int}  $visit
     * @return array{views: int, duration: int}
     */
    private function close(array $visit): array
    {
        return ['views' => $visit['views'], 'duration' => $visit['last'] - $visit['first'] + $visit['tail']];
    }

    /**
     * Page views and visitors per hour (today) or per day.
     *
     * @return array<int, array{label: string, views: int, visitors: int}>
     */
    public function timeseries(): array
    {
        $hourly = $this->from->diffInDays($this->to) < 1;
        $buckets = [];
        $cursor = $hourly ? $this->from : $this->from->startOfDay();

        while ($cursor <= $this->to) {
            $key = $hourly ? $cursor->format('Y-m-d H') : $cursor->format('Y-m-d');
            $buckets[$key] = ['label' => $hourly ? $cursor->format('H:00') : $cursor->translatedFormat('j M'), 'views' => 0, 'visitors' => []];
            $cursor = $hourly ? $cursor->addHour() : $cursor->addDay();
        }

        $this->query()->select(['visitor_hash', 'viewed_at'])->lazy(2000)->each(function (PageView $v) use (&$buckets, $hourly) {
            $key = $v->viewed_at->format($hourly ? 'Y-m-d H' : 'Y-m-d');

            if (isset($buckets[$key])) {
                $buckets[$key]['views']++;
                $buckets[$key]['visitors'][$v->visitor_hash] = true;
            }
        });

        return array_values(array_map(fn ($b) => [
            'label' => $b['label'],
            'views' => $b['views'],
            'visitors' => count($b['visitors']),
        ], $buckets));
    }

    /**
     * @return Collection<int, array{label: string, views: int, visitors: int}>
     */
    public function breakdown(string $column, int $limit = 8): Collection
    {
        abort_unless(in_array($column, ['path', 'referrer_host', 'device', 'browser', 'os', 'country', 'locale', 'utm_source'], true), 400);

        return $this->query()
            ->whereNotNull($column)
            ->selectRaw("{$column} as label, count(*) as views, count(distinct visitor_hash) as visitors")
            ->groupBy($column)
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->getAttribute('label'),
                'views' => (int) $row->getAttribute('views'),
                'visitors' => (int) $row->getAttribute('visitors'),
            ]);
    }

    /**
     * Visitors seen in the last few minutes (signed in or not).
     */
    public function activeNow(int $minutes = 5): int
    {
        return (int) PageView::query()
            ->when($this->clubId !== null, fn ($q) => $q->where('club_id', $this->clubId))
            ->where('viewed_at', '>=', now()->subMinutes($minutes))
            ->selectRaw('count(distinct visitor_hash) as c')
            ->value('c');
    }

    /**
     * @return Collection<int, array{path: string, at: string, device: string}>
     */
    public function liveFeed(int $limit = 12): Collection
    {
        return PageView::query()
            ->when($this->clubId !== null, fn ($q) => $q->where('club_id', $this->clubId))
            ->latest('viewed_at')->latest('id')
            ->limit($limit)
            ->get(['path', 'viewed_at', 'device'])
            ->map(fn (PageView $v) => ['path' => $v->path, 'at' => $v->viewed_at->diffForHumans(short: true), 'device' => $v->device]);
    }
}
