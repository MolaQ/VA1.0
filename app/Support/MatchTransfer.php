<?php

namespace App\Support;

use App\Models\Club;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One match with everything the analysis and the coders produced, as a portable array:
 * sets, lineups, actions, rallies, 2D frames and named tracks. Players travel by jersey
 * number (and name), so the match can be imported into another copy of VVA (another
 * computer, the home server) where the database ids are different.
 */
class MatchTransfer
{
    public const VERSION = 1;

    /** Child tables keyed by game_id, in insert order. */
    private const TABLES = ['game_sets', 'game_actions', 'game_rallies', 'game_frames', 'game_tracks'];

    /**
     * @return array<string, mixed>
     */
    public static function export(Game $game): array
    {
        $game->loadMissing(['team', 'club']);
        $data = [
            'vva_match_export' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'club' => $game->club->name,
            'team' => $game->team->name,
            'game' => (array) DB::table('games')->where('id', $game->id)->first(),
        ];

        foreach (self::TABLES as $table) {
            $data[$table] = Schema::hasTable($table)
                ? DB::table($table)->where('game_id', $game->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()
                : [];
        }
        $setIds = array_column($data['game_sets'], 'id');
        $data['set_lineups'] = DB::table('set_lineups')->whereIn('game_set_id', $setIds)->get()->map(fn ($r) => (array) $r)->all();

        $playerIds = array_unique(array_filter(array_merge(
            array_column($data['game_actions'], 'player_id'),
            array_column($data['set_lineups'], 'player_id'),
        )));
        $data['players'] = Player::query()->whereIn('id', $playerIds)->get()
            ->map(fn (Player $p) => $p->only(['id', 'number', 'first_name', 'last_name', 'nickname', 'position']))->values()->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function import(array $data, Club $club, Team $team): Game
    {
        if (($data['vva_match_export'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('To nie jest plik eksportu meczu VVA (vva_match_export).');
        }

        return DB::transaction(function () use ($data, $club, $team) {
            // players: same team + same number, else create (#number, with the name from the file)
            $playerMap = [];
            foreach ($data['players'] ?? [] as $p) {
                $local = $p['number'] !== null
                    ? Player::query()->where('club_id', $club->id)->where('team_id', $team->id)->where('number', $p['number'])->first()
                    : null;
                $local ??= Player::query()->create([
                    'club_id' => $club->id,
                    'team_id' => $team->id,
                    'number' => $p['number'],
                    'first_name' => $p['first_name'],
                    'last_name' => $p['last_name'],
                    'nickname' => $p['nickname'] ?? null,
                ]);
                $playerMap[(int) $p['id']] = $local->id;
            }

            $gameRow = self::fit('games', $data['game'], ['club_id' => $club->id, 'team_id' => $team->id]);
            $gameId = (int) DB::table('games')->insertGetId($gameRow);

            $setMap = [];
            foreach ($data['game_sets'] ?? [] as $row) {
                $setMap[(int) $row['id']] = (int) DB::table('game_sets')->insertGetId(self::fit('game_sets', $row, ['game_id' => $gameId]));
            }
            foreach ($data['set_lineups'] ?? [] as $row) {
                if (isset($setMap[(int) $row['game_set_id']], $playerMap[(int) $row['player_id']])) {
                    DB::table('set_lineups')->insert(self::fit('set_lineups', $row, [
                        'game_set_id' => $setMap[(int) $row['game_set_id']],
                        'player_id' => $playerMap[(int) $row['player_id']],
                    ]));
                }
            }
            foreach (['game_actions', 'game_rallies', 'game_frames', 'game_tracks'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (array_chunk($data[$table] ?? [], 500) as $chunk) {
                    DB::table($table)->insert(array_map(fn (array $row) => self::fit($table, $row, array_filter([
                        'game_id' => $gameId,
                        'club_id' => array_key_exists('club_id', $row) ? $club->id : null,
                        'game_set_id' => array_key_exists('game_set_id', $row) ? ($setMap[(int) $row['game_set_id']] ?? null) : null,
                        'player_id' => array_key_exists('player_id', $row) ? ($playerMap[(int) $row['player_id']] ?? null) : null,
                    ], fn ($v, $k) => array_key_exists($k, $row), ARRAY_FILTER_USE_BOTH)), $chunk));
                }
            }

            return Game::query()->findOrFail($gameId);
        });
    }

    /**
     * The row without its id, with new foreign keys, users cleared, and only the columns
     * this database has (the file may come from an older or newer VVA).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private static function fit(string $table, array $row, array $set): array
    {
        unset($row['id']);
        foreach (['created_by', 'user_id', 'reviewed_by'] as $userColumn) {
            if (array_key_exists($userColumn, $row)) {
                $row[$userColumn] = null;
            }
        }
        $row = array_merge($row, $set);

        return array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
    }
}
