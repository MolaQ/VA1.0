<?php

namespace App\Http\Controllers;

use App\Analytics\VisitorHasher;
use App\Models\PageView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives `navigator.sendBeacon` pings with the time a page was actually visible.
 * Only the visitor that produced a page view can update it (same daily hash).
 */
class AnalyticsBeaconController extends Controller
{
    public function __invoke(Request $request, VisitorHasher $hasher): Response
    {
        $data = json_decode($request->getContent(), true);

        if (! is_array($data) || ! isset($data['v'], $data['t']) || ! is_numeric($data['v']) || ! is_numeric($data['t'])) {
            return response()->noContent();
        }

        $seconds = (int) min(max(0, (int) $data['t']), 4 * 3600);

        PageView::query()
            ->whereKey((int) $data['v'])
            ->where('visitor_hash', $hasher->hash((string) $request->ip(), (string) $request->userAgent()))
            ->where('viewed_at', '>=', now()->subHours(6))
            ->update(array_filter([
                'engaged_seconds' => $seconds,
                'is_pwa' => ($data['pwa'] ?? false) === true ? true : null,
            ], fn ($v) => $v !== null));

        return response()->noContent();
    }
}
