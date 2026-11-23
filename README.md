# VVA – Volleyball Video Analyze

Analiza gry zawodników siatkówki na podstawie nagrań YouTube. Wielu klubów (SaaS), role
**administrator / trener / zawodnik / rodzic**, statystyki liczone z akcji powiązanych z momentem
w nagraniu, aktualizacje na żywo (Laravel Reverb), API dla modułu AI, PWA, PL / EN / IT.

Stos: **Laravel 13 · Livewire 4 · Tailwind CSS 4 · Reverb · Sanctum · Pest**.

## Szybki start

```bash
composer setup              # zależności, .env, klucz, migracje, build frontu
php artisan migrate:fresh --seed   # klub demo z przeanalizowanymi meczami
composer dev                # serwer, kolejka, logi, Vite
php artisan reverb:start    # WebSockets (statystyki na żywo)
```

Konta demo (hasło `password`): `admin@vva.test`, `trener@vva.test`, `zawodnik@vva.test`,
`rodzic@vva.test` (widzi tylko dziecko – libero #26), `super@vva.test` (właściciel platformy).
Drugi klub (`trener@sokol.test`) pokazuje izolację danych między klubami.

## Architektura

| Obszar | Gdzie |
| --- | --- |
| Multi-tenancy | `clubs` + `memberships (club_id, user_id, role)`; `users.current_club_id`; middleware `EnsureClubContext`; trait `BelongsToClub` (`forClub()` scope); polityki `GamePolicy`, `PlayerPolicy`, `ClubPolicy` |
| Rodzic → dziecko | tabela `guardian_player`; `App\Support\Visibility` ogranicza wiersze statystyk |
| Model meczu | `games` (link YouTube → `youtube_id`), `game_sets`, `set_lineups` (S1–S5: pozycja w rotacji lub `*`), `game_actions` (zawodnik, element, ocena `# + ! - / =`, `video_time`, źródło `ai/manual`, `confidence`, `external_id`) |
| Statystyki | `App\Stats\GameStatsCalculator` + `PlayerStatLine` – wszystko liczone z akcji (Pkt, BP, Bilans, Zagrywka Eff%, Przyjęcie Poz%/Perf%, Atak Kill%/Eff%, Blok) |
| Zapis akcji | `App\Services\ActionIngestor` – jedno wejście dla API AI i kodowania ręcznego; idempotentne po `external_id`; emituje `GameActionsRecorded` |
| Na żywo | kanały prywatne `games.{id}` i `clubs.{id}` (`routes/channels.php`), Livewire `echo-private:` listeners |
| Analiza AI | `RequestVideoAnalysis` wysyła mecz do `VVA_ANALYZER_URL`; analizator odsyła wyniki przez API |
| i18n | `lang/pl.json`, `lang/it.json` (klucze po angielsku), `lang/{pl,it}/validation.php`; `SetLocale`: profil → sesja → przeglądarka → `pl` |
| Analityka bez cookies | middleware `TrackPageView` + `App\Analytics\*`: odwiedzający = `sha256(dzienna sól + IP + UA)`, bez zapisu IP/UA, filtr botów, respektuje DNT/GPC, czas zaangażowania przez `sendBeacon` (`POST /a/e`), retencja `ANALYTICS_RETENTION_DAYS` (`model:prune`). Panel: **Klub → Analityka** |
| Online i toasty | kanał presence `online.clubs.{id}` (lista osób online w nagłówku), zdarzenie `ClubActivity` na `clubs.{id}` → toasty tłumaczone w przeglądarce (nowy mecz, koniec analizy AI, nowy członek, logowanie – dla sztabu) |
| PWA | `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, ikony w `public/icons`; logowanie z „zapamiętaj mnie” domyślnie |

## API dla modułu AI (`/api/v1`, `Authorization: Bearer <token>`)

Token tworzy administrator w **Panel klubu → Integracje AI** (uprawnienie `analysis:write`).

| Metoda | Ścieżka | Opis |
| --- | --- | --- |
| GET | `/games/{id}` | metadane meczu i skład (numer koszulki → id zawodnika) |
| PATCH | `/games/{id}/analysis` | `status` (`queued`, `processing`, `completed`, `failed`), `progress` 0–100, `message` |
| POST | `/games/{id}/actions` | do 1000 akcji naraz, idempotentne po `external_id` |
| PUT | `/games/{id}/sets/{nr}` | wynik seta, `video_start`, `lineup[]` (rotacja / zmiennicy) |
| DELETE | `/games/{id}/actions?source=ai` | czyszczenie przed ponowną analizą |

```json
POST /api/v1/games/42/actions
{ "actions": [
  { "external_id": "det-000183", "set": 1, "rally": 14, "player_number": 13,
    "skill": "attack", "grade": "#", "video_time": 812.4,
    "zone_from": 4, "zone_to": 1, "confidence": 0.93 }
] }
```

`skill`: `serve, reception, attack, block, dig, set, freeball` · `grade`: `# + ! - / =`

## Testy

```bash
composer test   # pint --test, phpstan (larastan, level 7), pest
```
