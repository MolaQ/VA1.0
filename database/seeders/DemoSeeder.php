<?php

namespace Database\Seeders;

use App\Enums\ActionSource;
use App\Enums\AnalysisStatus;
use App\Enums\Grade;
use App\Enums\Position;
use App\Enums\Role;
use App\Enums\Skill;
use App\Events\GameActionsRecorded;
use App\Events\GameAnalysisUpdated;
use App\Models\Club;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\ActionIngestor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * A realistic demo club with two scouted matches, so every screen has data.
 * Logins (password "password"): admin@vva.test, trener@vva.test, zawodnik@vva.test, rodzic@vva.test, super@vva.test
 */
class DemoSeeder extends Seeder
{
    private const ROSTER = [
        [2, 'Adrian', 'Kowalczyk', Position::Opposite],
        [13, 'Bartosz', 'Nowicki', Position::Outside],
        [24, 'Kacper', 'Zieliński', Position::Middle],
        [25, 'Michał', 'Wróbel', Position::Outside],
        [26, 'Szymon', 'Lis', Position::Libero],
        [32, 'Oskar', 'Mazur', Position::Setter],
        [33, 'Jan', 'Kaczmarek', Position::Outside],
        [34, 'Dawid', 'Pawlak', Position::Middle],
        [38, 'Filip', 'Sowa', Position::Setter],
        [40, 'Igor', 'Dudek', Position::Middle],
        [44, 'Tymon', 'Krawczyk', Position::Opposite],
        [48, 'Mateusz', 'Jabłoński', Position::Middle],
        [49, 'Łukasz', 'Górski', Position::Libero],
    ];

    public function run(ActionIngestor $ingestor): void
    {
        mt_srand(2026);

        $club = Club::create(['name' => 'VVA Volley Kraków', 'city' => 'Kraków', 'accent_color' => '#3B82F6']);
        $team = $club->teams()->create(['name' => 'Seniorzy', 'category' => 'II liga', 'season' => '2026/2027']);
        $club->teams()->create(['name' => 'Juniorzy', 'category' => 'U19', 'season' => '2026/2027']);

        $players = collect(self::ROSTER)->map(fn ($p) => $club->players()->create([
            'team_id' => $team->id, 'number' => $p[0], 'first_name' => $p[1], 'last_name' => $p[2], 'position' => $p[3],
        ]));

        $admin = $this->user('Anna Administrator', 'admin@vva.test');
        $trainer = $this->user('Tomasz Trener', 'trener@vva.test');
        $playerUser = $this->user('Bartosz Nowicki', 'zawodnik@vva.test');
        $parent = $this->user('Ewa Rodzic', 'rodzic@vva.test');
        $super = $this->user('Super Admin', 'super@vva.test');
        $super->forceFill(['is_super_admin' => true])->save();

        $club->addMember($admin, Role::Admin);
        $club->addMember($trainer, Role::Trainer);
        $club->addMember($playerUser, Role::Player);
        $club->addMember($parent, Role::Parent);

        /** @var Player $bartosz */
        $bartosz = $players->firstWhere('number', 13);
        $bartosz->update(['user_id' => $playerUser->id]);
        $parent->children()->attach($players->firstWhere('number', 26)->id);

        foreach ([$admin, $trainer, $playerUser, $parent, $super] as $u) {
            $u->switchClub($club);
        }

        // Second club proves tenant isolation.
        $other = Club::create(['name' => 'Sokół Tarnów', 'city' => 'Tarnów']);
        $otherTeam = $other->teams()->create(['name' => 'Seniorzy']);
        $other->addMember($this->user('Inny Trener', 'trener@sokol.test'), Role::Trainer);
        $other->players()->create(['team_id' => $otherTeam->id, 'number' => 7, 'first_name' => 'Paweł', 'last_name' => 'Obcy', 'position' => Position::Outside]);

        Event::fake([GameActionsRecorded::class, GameAnalysisUpdated::class]); // no broadcasting while seeding

        $this->scoutedGame($ingestor, $team, $players->all(), 'AZS Politechnika', now()->subDays(3), 'https://www.youtube.com/watch?v=XCXJATWV9O4', [[25, 21], [25, 23], [22, 25], [25, 19]]);
        $this->scoutedGame($ingestor, $team, $players->all(), 'MKS Będzin II', now()->subDays(10), null, [[20, 25], [25, 22], [25, 27], [18, 25]]);

        $club->games()->create([
            'team_id' => $team->id, 'opponent' => 'Wisła Kraków', 'played_at' => now()->subDay(), 'is_home' => true,
            'analysis_status' => AnalysisStatus::Pending,
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'email_verified_at' => now()]);
    }

    /**
     * @param  array<int, Player>  $players
     * @param  array<int, array{0: int, 1: int}>  $scores
     */
    private function scoutedGame(ActionIngestor $ingestor, Team $team, array $players, string $opponent, mixed $date, ?string $url, array $scores): Game
    {
        $game = $team->games()->create([
            'club_id' => $team->club_id, 'opponent' => $opponent, 'played_at' => $date, 'is_home' => true,
            'youtube_url' => $url, 'analysis_status' => AnalysisStatus::Completed, 'analysis_progress' => 100,
        ]);

        $byNumber = collect($players)->keyBy('number');
        $starters = [32 => 1, 13 => 2, 34 => 3, 2 => 4, 25 => 5, 48 => 6];
        $hitters = [2, 13, 25, 34, 48, 24];
        $receivers = [13, 25, 26, 49];
        $servers = array_keys($starters);
        $time = 30.0;
        $items = [];
        $n = 0;

        foreach ($scores as $i => [$us, $them]) {
            $setNo = $i + 1;
            $set = $game->sets()->create(['number' => $setNo, 'score_us' => $us, 'score_them' => $them, 'video_start' => $time]);

            foreach ($starters as $number => $rot) {
                $set->lineups()->create(['player_id' => $byNumber[$number]->id, 'rotation_position' => (($rot + $i - 1) % 6) + 1]);
            }
            $set->lineups()->create(['player_id' => $byNumber[26]->id, 'is_substitute' => true]);
            if ($setNo > 1) {
                $set->lineups()->create(['player_id' => $byNumber[24]->id, 'is_substitute' => true]);
            }

            for ($rally = 1; $rally <= $us + $them; $rally++) {
                $time += mt_rand(18, 40);
                $weServe = mt_rand(0, 1) === 1;

                if ($weServe) {
                    $items[] = $this->item(++$n, $setNo, $rally, $servers[array_rand($servers)], Skill::Serve, $this->pick(['#' => 6, '+' => 10, '!' => 40, '-' => 18, '/' => 4, '=' => 22]), $time);
                } else {
                    $items[] = $this->item(++$n, $setNo, $rally, $receivers[array_rand($receivers)], Skill::Reception, $this->pick(['#' => 22, '+' => 33, '!' => 20, '-' => 18, '/' => 3, '=' => 4]), $time);
                }

                $items[] = $this->item(++$n, $setNo, $rally, $hitters[array_rand($hitters)], Skill::Attack, $this->pick(['#' => 48, '+' => 12, '!' => 15, '-' => 8, '/' => 7, '=' => 10]), $time + mt_rand(3, 8));

                if (mt_rand(1, 100) <= 28) {
                    $items[] = $this->item(++$n, $setNo, $rally, [34, 48, 24, 2][mt_rand(0, 3)], Skill::Block, $this->pick(['#' => 30, '+' => 40, '!' => 15, '=' => 15]), $time + mt_rand(9, 14));
                }
            }
        }

        foreach (array_chunk($items, 250) as $chunk) {
            $ingestor->ingest($game, $chunk, ActionSource::Ai);
        }

        $game->refreshResult();

        return $game;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $n, int $set, int $rally, int $number, Skill $skill, string $grade, float $time): array
    {
        return [
            'external_id' => 'demo-'.$n,
            'set' => $set,
            'rally' => $rally,
            'player_number' => $number,
            'skill' => $skill->value,
            'grade' => $grade,
            'video_time' => $time,
            'confidence' => mt_rand(70, 99) / 100,
        ];
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $grade => $weight) {
            if (($roll -= $weight) <= 0) {
                return Grade::from((string) $grade)->value;
            }
        }

        return Grade::Neutral->value;
    }
}
