<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persists UI preferences (theme, language) on the user's profile, so they
 * follow the user across devices and the installed mobile app.
 */
class PreferencesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['dark', 'light', 'system'])],
            'locale' => ['sometimes', Rule::in(array_keys(Locales::supported()))],
        ]);

        $request->user()->forceFill($data)->save();

        if (isset($data['locale'])) {
            $request->session()->put('locale', $data['locale']);
        }

        return response()->json(['ok' => true]);
    }
}
