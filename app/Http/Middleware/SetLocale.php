<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the UI language: user preference → session → browser → app default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidates = [
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->getPreferredLanguage(array_keys(Locales::supported())),
        ];

        foreach ($candidates as $locale) {
            if (Locales::isSupported($locale)) {
                App::setLocale($locale);

                break;
            }
        }

        return $next($request);
    }
}
