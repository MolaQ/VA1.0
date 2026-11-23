<?php

namespace App\Analytics;

use App\Models\PageView;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Tracker
{
    /** A visit (session) ends after this many minutes of inactivity. */
    public const SESSION_GAP_MINUTES = 30;

    public function __construct(private readonly VisitorHasher $hasher) {}

    public function shouldTrack(Request $request): bool
    {
        if (! config('analytics.enabled', true) || ! $request->isMethod('GET')) {
            return false;
        }

        if ($request->is('api/*', 'livewire*', 'broadcasting/*', 'up', 'locale/*', 'a/*', 'sw.js', 'manifest.webmanifest', 'build/*')) {
            return false;
        }

        // Respect explicit opt-out signals.
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1') {
            return false;
        }

        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose')));

        if (str_contains($purpose, 'prefetch')) {
            return false;
        }

        return ! (new UserAgent((string) $request->userAgent()))->isBot();
    }

    public function record(Request $request): PageView
    {
        $ua = new UserAgent((string) $request->userAgent());
        $hash = $this->hasher->hash((string) $request->ip(), (string) $request->userAgent());

        /** @var User|null $user */
        $user = $request->user();

        $isEntry = ! PageView::query()
            ->where('visitor_hash', $hash)
            ->where('viewed_at', '>=', now()->subMinutes(self::SESSION_GAP_MINUTES))
            ->exists();

        return PageView::create([
            'club_id' => $user?->current_club_id,
            'user_id' => $user?->id,
            'visitor_hash' => $hash,
            'path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
            'route' => $request->route()?->getName(),
            'referrer_host' => $this->referrerHost($request),
            'utm_source' => Str::limit((string) $request->query('utm_source', $request->query('ref', '')), 80, '') ?: null,
            'device' => $ua->device(),
            'browser' => $ua->browser(),
            'os' => $ua->os(),
            'country' => $this->country($request),
            'locale' => app()->getLocale(),
            'is_pwa' => $request->query('source') === 'pwa',
            'is_entry' => $isEntry,
            'viewed_at' => now(),
        ]);
    }

    private function referrerHost(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $host === $request->getHost()) {
            return null;
        }

        return Str::limit(Str::after($host, 'www.'), 120, '');
    }

    /**
     * Country from a trusted edge proxy header (Cloudflare, CloudFront, Fastly…), never from a geo-IP lookup.
     */
    private function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'Fastly-Geo-Country', 'X-Country-Code'] as $header) {
            $code = strtoupper((string) $request->header($header));

            if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX') {
                return $code;
            }
        }

        return null;
    }
}
