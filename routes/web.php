<?php

use App\Http\Controllers\AnalyticsBeaconController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PreferencesController;
use App\Livewire\Analytics;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Club\Integrations;
use App\Livewire\Club\Members;
use App\Livewire\Club\Settings;
use App\Livewire\Club\Teams;
use App\Livewire\Dashboard;
use App\Livewire\Games\GameForm;
use App\Livewire\Games\GameIndex;
use App\Livewire\Games\GameShow;
use App\Livewire\Onboarding;
use App\Livewire\Platform\Clubs;
use App\Livewire\Players\PlayerIndex;
use App\Livewire\Players\PlayerShow;
use App\Livewire\Profile;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/locale/{locale}', LocaleController::class)->name('locale.switch');
Route::post('/a/e', AnalyticsBeaconController::class)->middleware('throttle:120,1')->name('analytics.beacon');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/onboarding', Onboarding::class)->name('onboarding');
    Route::post('/preferences', PreferencesController::class)->name('preferences.update');
    Route::get('/profile', Profile::class)->name('profile');

    Route::middleware('club')->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('dashboard');

        Route::get('/games', GameIndex::class)->name('games.index');
        Route::get('/games/create', GameForm::class)->name('games.create');
        Route::get('/games/{game}', GameShow::class)->name('games.show');
        Route::get('/games/{game}/edit', GameForm::class)->name('games.edit');

        Route::get('/players', PlayerIndex::class)->name('players.index');
        Route::get('/players/{player}', PlayerShow::class)->name('players.show');

        Route::prefix('club')->name('club.')->group(function () {
            Route::get('/members', Members::class)->name('members');
            Route::get('/teams', Teams::class)->name('teams');
            Route::get('/integrations', Integrations::class)->name('integrations');
            Route::get('/settings', Settings::class)->name('settings');
        });

        Route::get('/analytics', Analytics::class)->name('analytics');
        Route::get('/platform/clubs', Clubs::class)->name('platform.clubs');
    });
});
