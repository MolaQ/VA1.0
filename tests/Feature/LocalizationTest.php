<?php

use App\Enums\Role;

beforeEach(function () {
    // Symfony's test client sends "Accept-Language: en-us" by default; act like a Polish browser.
    $this->withHeader('Accept-Language', 'pl-PL,pl;q=0.9');
});

it('defaults to Polish', function () {
    $this->withHeader('Accept-Language', '')->get('/login')->assertOk()->assertSee('Witaj ponownie')->assertSee('lang="pl"', false);
});

it('switches the language and remembers it in the session', function (string $locale, string $text) {
    $this->get("/locale/{$locale}")->assertRedirect();
    $this->get('/login')->assertSee($text)->assertSee('lang="'.$locale.'"', false);
})->with([
    ['en', 'Welcome back'],
    ['it', 'Bentornato'],
]);

it('stores the language on the user profile', function () {
    ['club' => $club] = clubWithGame();
    $user = member($club, Role::Admin);

    $this->actingAs($user)->get('/locale/it');

    expect($user->fresh()->locale)->toBe('it');

    // A fresh session (another device) still gets Italian.
    $this->flushSession();
    $this->actingAs($user->fresh())->get('/dashboard')->assertSee('Partite');
});

it('picks a supported browser language for guests', function () {
    $this->withHeader('Accept-Language', 'it-IT,it;q=0.9')->get('/login')->assertSee('Bentornato');
});

it('ignores unsupported languages', function () {
    $this->get('/locale/de')->assertNotFound();
});

it('saves theme and language preferences', function () {
    ['club' => $club] = clubWithGame();
    $user = member($club, Role::Player);

    $this->actingAs($user)->postJson('/preferences', ['theme' => 'light', 'locale' => 'en'])->assertOk();

    expect($user->fresh())->theme->toBe('light')->locale->toBe('en');

    $this->actingAs($user->fresh())->get('/dashboard')->assertSee('"light"', false);
});

it('ships the same translation keys for every language', function () {
    $pl = array_keys(json_decode(file_get_contents(lang_path('pl.json')), true));
    $it = array_keys(json_decode(file_get_contents(lang_path('it.json')), true));

    expect($it)->toEqualCanonicalizing($pl);
});

it('links the PWA manifest and service worker assets', function () {
    $this->get('/')->assertSee('rel="manifest"', false);

    expect(file_exists(public_path('manifest.webmanifest')))->toBeTrue()
        ->and(file_exists(public_path('sw.js')))->toBeTrue()
        ->and(json_decode(file_get_contents(public_path('manifest.webmanifest')), true)['display'])->toBe('standalone');
});
