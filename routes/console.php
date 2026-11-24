<?php

use App\Models\Club;
use App\Models\Game;
use App\Models\PageView;
use App\Models\Team;
use App\Models\User;
use App\Support\MatchTransfer;
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

Artisan::command('vva:queue {--reset : put interrupted analyses (processing) back in the queue}', function () {
    $games = Game::query()->whereIn('analysis_status', ['queued', 'processing'])->orderBy('id')->get();
    if ($games->isEmpty()) {
        $this->info('Kolejka pusta / queue empty.');

        return 0;
    }
    $this->table(['id', 'klub', 'status', '%', 'YouTube', 'aktualizacja', 'opis'], $games->map(fn (Game $g) => [
        $g->id, $g->club_id, $g->analysis_status->value, $g->analysis_progress, $g->youtube_id ?? 'BRAK',
        $g->analysis_updated_at?->diffForHumans() ?? '-', mb_strimwidth((string) $g->analysis_message, 0, 50, '…'),
    ])->all());
    if ($this->option('reset')) {
        $n = Game::query()->where('analysis_status', 'processing')->update([
            'analysis_status' => 'queued', 'analysis_progress' => 0, 'analysis_started_at' => null, 'analysis_updated_at' => now(),
        ]);
        $this->info("Przywrócono do kolejki: {$n}");
    }

    return 0;
})->purpose('Show the analysis queue (and reset interrupted analyses)');

Artisan::command('vva:export {game : match id (from the address /games/ID)} {file? : output file}', function () {
    $game = Game::query()->find((int) $this->argument('game'));
    if ($game === null) {
        $this->error('Nie ma meczu o tym numerze.');

        return 1;
    }
    $fileArg = $this->argument('file');
    $file = is_string($fileArg) && $fileArg !== '' ? $fileArg : 'mecz-'.$game->id.'.vva.json';
    file_put_contents($file, json_encode(MatchTransfer::export($game), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->info("Zapisano mecz {$game->id} ({$game->opponent}) do pliku: ".realpath($file));

    return 0;
})->purpose('Save one match with its analysis to a file (to move it to another VVA)');

Artisan::command('vva:import {file} {--club= : club id (default: the only/first club)} {--team= : team id (default: team with the same name, else the first)}', function () {
    $fileArg = $this->argument('file');
    $file = is_string($fileArg) ? $fileArg : '';
    if (! is_file($file)) {
        $this->error("Nie ma pliku {$file}");

        return 1;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (! is_array($data)) {
        $this->error('Plik nie jest poprawnym JSON-em.');

        return 1;
    }
    $club = $this->option('club') ? Club::query()->find((int) $this->option('club'))
        : (Club::query()->where('name', $data['club'] ?? '')->first() ?? Club::query()->orderBy('id')->first());
    if ($club === null) {
        $this->error('Brak klubu w tej bazie.');

        return 1;
    }
    $team = $this->option('team') ? Team::query()->where('club_id', $club->id)->find((int) $this->option('team'))
        : (Team::query()->where('club_id', $club->id)->where('name', $data['team'] ?? '')->first()
            ?? Team::query()->where('club_id', $club->id)->orderBy('id')->first());
    if ($team === null) {
        $this->error("Klub {$club->name} nie ma drużyny.");

        return 1;
    }
    $game = MatchTransfer::import($data, $club, $team);
    $this->info("Zaimportowano jako mecz {$game->id}: {$club->name} / {$team->name} vs {$game->opponent}");

    return 0;
})->purpose('Load a match saved with vva:export');

// Cookie-less analytics: delete raw page views past the retention window.
Schedule::command('model:prune', ['--model' => [PageView::class]])->daily();
