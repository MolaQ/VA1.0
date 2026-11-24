<?php

namespace App\Http\Middleware;

use App\Analytics\Tracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a page view for every HTML page served (including wire:navigate visits).
 * The view id is shared with the page so the browser can report engaged time.
 */
class TrackPageView
{
    public function __construct(private readonly Tracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $view = null;

        if ($this->tracker->shouldTrack($request)) {
            try {
                $view = $this->tracker->record($request);
                View::share('pageViewId', $view->id);
            } catch (Throwable $e) {
                report($e); // analytics must never break the app
            }
        }

        $response = $next($request);

        if ($view !== null && ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $view->delete();
        }

        return $response;
    }
}
