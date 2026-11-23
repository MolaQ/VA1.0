<?php

use App\Analytics\Report;
use App\Analytics\UserAgent;
use App\Enums\Role;
use App\Events\ClubActivity;
use App\Livewire\Club\Members;
use App\Livewire\Games\GameForm;
use App\Models\PageView;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

const CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

it('records a cookie-less page view without storing IP or user agent', function () {
    $this->withHeaders(['User-Agent' => CHROME, 'Referer' => 'https://www.google.com/search?q=vva'])
        ->get('/login?utm_source=newsletter')
        ->assertOk()
        ->assertSee('id="vva-view"', false);

    $view = PageView::sole();

    expect($view->path)->toBe('/login')
        ->and($view->device)->toBe('mobile')
        ->and($view->browser)->toBe('Chrome')
        ->and($view->os)->toBe('Android')
        ->and($view->referrer_host)->toBe('google.com')
        ->and($view->utm_source)->toBe('newsletter')
        ->and($view->is_entry)->toBeTrue()
        ->and(strlen($view->visitor_hash))->toBe(64)
        ->and(json_encode($view->getAttributes()))->not->toContain('127.0.0.1')->not->toContain('Pixel');

    // No analytics cookie: only the framework's own session / CSRF cookies.
    expect(collect($this->get('/login')->headers->getCookies())->map->getName()->all())
        ->each->toBeIn([config('session.cookie'), 'XSRF-TOKEN']);
});

it('groups views of the same visitor into one visit', function () {
    $this->withHeader('User-Agent', CHROME)->get('/login');
    $this->withHeader('User-Agent', CHROME)->get('/register');

    expect(PageView::where('is_entry', true)->count())->toBe(1)
        ->and(PageView::count())->toBe(2);
});

it('ignores bots, opt-out signals and non-page requests', function () {
    $this->withHeader('User-Agent', 'Googlebot/2.1 (+http://www.google.com/bot.html)')->get('/login');
    $this->withHeaders(['User-Agent' => CHROME, 'DNT' => '1'])->get('/login');
    $this->withHeaders(['User-Agent' => CHROME, 'Sec-GPC' => '1'])->get('/login');
    $this->withHeader('User-Agent', CHROME)->get('/up');

    expect(PageView::count())->toBe(0);
});

it('classifies devices', function (string $ua, string $device) {
    expect((new UserAgent($ua))->device())->toBe($device);
})->with([
    [CHROME, 'mobile'],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1', 'tablet'],
    ['Mozilla/5.0 (SMART-TV; Linux; Tizen 7.0) AppleWebKit/537.36 SamsungBrowser/4.0 TV Safari/537.36', 'tv'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', 'desktop'],
]);

it('accepts the engagement beacon only from the same visitor', function () {
    $this->withHeader('User-Agent', CHROME)->get('/login');
    $view = PageView::sole();

    $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'Other browser'], json_encode(['v' => $view->id, 't' => 99]))
        ->assertNoContent();
    expect($view->fresh()->engaged_seconds)->toBe(0);

    $this->call('POST', '/a/e', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => CHROME], json_encode(['v' => $view->id, 't' => 42, 'pwa' => true]))
        ->assertNoContent();
    expect($view->fresh())->engaged_seconds->toBe(42)->is_pwa->toBeTrue();
});

it('computes visits, bounce rate and duration', function () {
    ['club' => $club] = clubWithGame();
    $base = now()->subHours(3);
    $row = fn (string $hash, int $min, bool $entry, int $engaged = 0) => PageView::create([
        'club_id' => $club->id, 'visitor_hash' => str_pad($hash, 64, '0'), 'path' => '/x', 'device' => 'desktop',
        'is_entry' => $entry, 'engaged_seconds' => $engaged, 'viewed_at' => $base->copy()->addMinutes($min),
    ]);

    $row('a', 0, true);          // visit 1: 3 pages, 10 min + 30 s
    $row('a', 4, false);
    $row('a', 10, false, 30);
    $row('a', 60, true, 20);     // visit 2 (after a 50 min gap): bounce, 20 s
    $row('b', 5, true, 10);      // visit 3: bounce, 10 s

    $summary = Report::forPeriod($club->id, '7d')->summary();

    expect($summary['pageviews'])->toBe(5)
        ->and($summary['visitors'])->toBe(2)
        ->and($summary['visits'])->toBe(3)
        ->and(round($summary['bounce_rate'], 2))->toBe(0.67)
        ->and($summary['avg_duration'])->toBe((int) round((630 + 20 + 10) / 3));
});

it('shows analytics to club admins only, scoped to their club', function () {
    ['club' => $club] = clubWithGame();
    PageView::create(['club_id' => $club->id, 'visitor_hash' => str_repeat('a', 64), 'path' => '/our-page', 'device' => 'desktop', 'viewed_at' => now()]);
    PageView::create(['club_id' => null, 'visitor_hash' => str_repeat('b', 64), 'path' => '/someone-else', 'device' => 'desktop', 'viewed_at' => now()]);

    $this->actingAs(member($club, Role::Admin))->get('/analytics')
        ->assertOk()->assertSee('/our-page')->assertDontSee('/someone-else');

    $this->actingAs(member($club, Role::Trainer))->get('/analytics')->assertForbidden();
});

it('broadcasts club activity for new matches and members', function () {
    Event::fake([ClubActivity::class]);
    ['club' => $club, 'team' => $team] = clubWithGame();
    $admin = member($club, Role::Admin);

    Livewire::actingAs($admin)->test(GameForm::class)
        ->set('team_id', $team->id)->set('opponent', 'AZS Test')->set('youtube_url', '')
        ->call('save');

    Livewire::actingAs($admin)->test(Members::class)
        ->set('name', 'Nowy Trener')->set('email', 'nowy@example.com')->set('role', 'trainer')
        ->call('invite');

    Event::assertDispatched(ClubActivity::class, fn (ClubActivity $e) => $e->type === ClubActivity::GAME_CREATED && $e->params['opponent'] === 'AZS Test');
    Event::assertDispatched(ClubActivity::class, fn (ClubActivity $e) => $e->type === ClubActivity::MEMBER_ADDED && $e->audience === 'staff');
});
