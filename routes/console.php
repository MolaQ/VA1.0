<?php

use App\Models\Club;
use App\Models\PageView;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Analysis agent tokens from the command line: always in the database this copy of VVA uses.
Artisan::command('vva:token {email? : user e-mail (default: first club admin)} {--club= : club id}', function () {
    $user = $this->argument('email')
        ? User::query()->where('email', $this->argument('email'))->first()
        : User::query()->whereHas('clubs')->orderBy('id')->get()->first(fn (User $u) => $u->clubs->contains(fn (Club $c) => $u->isStaffOf($c->id)));
    if ($user === null) {
        $this->error('Nie znaleziono użytkownika / user not found.');

        return 1;
    }
    $club = $this->option('club')
        ? $user->clubs()->find((int) $this->option('club'))
        : $user->clubs()->get()->first(fn (Club $c) => $user->isStaffOf($c->id));
    if ($club === null) {
        $this->error('Użytkownik nie jest adminem ani trenerem w żadnym klubie.');

        return 1;
    }
    $token = $user->createToken('AI analyzer · '.$club->name, ['analysis:write', 'club:'.$club->id])->plainTextToken;
    $this->info("Token dla {$user->name}, klub {$club->name}:");
    $this->line($token);

    return 0;
})->purpose('Create an API token for the VVA Analyzer agent');

Artisan::command('vva:check-token {token}', function () {
    $conn = config('database.default');
    $this->line('Baza / database: '.$conn.' → '.config("database.connections.{$conn}.database"));
    $this->line('Tokenów w bazie / tokens in DB: '.PersonalAccessToken::query()->count());
    $arg = $this->argument('token');
    $raw = is_string($arg) ? trim($arg) : '';
    $pat = PersonalAccessToken::findToken($raw);
    if ($pat === null) {
        $id = str_contains($raw, '|') ? (int) explode('|', $raw, 2)[0] : null;
        $this->error('Token NIE pasuje do tej bazy.'.($id && PersonalAccessToken::query()->whereKey($id)->exists()
            ? " Token nr {$id} istnieje, ale reszta znaków się nie zgadza (ucięty/zmieniony przy kopiowaniu?)."
            : ($id ? " Tokenu nr {$id} nie ma w tej bazie: utworzono go w innej kopii VVA albo został unieważniony." : ' Brak numeru przed znakiem |.')));

        return 1;
    }
    $this->info("OK: token \"{$pat->name}\" użytkownika ".($pat->tokenable instanceof User ? $pat->tokenable->name : '?').', uprawnienia: '.implode(', ', (array) $pat->abilities));

    return 0;
})->purpose('Check an analyzer API token against this database');

// Cookie-less analytics: delete raw page views past the retention window.
Schedule::command('model:prune', ['--model' => [PageView::class]])->daily();
