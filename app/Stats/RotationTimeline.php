<?php

namespace App\Stats;

use App\Enums\Position;
use App\Models\Game;
use App\Models\GameRally;
use App\Models\GameSet;
use App\Models\Player;
use App\Stats\Report\Rally;
use App\Stats\Report\RallyBuilder;

/**
 * Who stands in which zone at every rally of the match, for the 2D court under the video.
 *
 * From each set's starting lineup (position I serves first) we move one position after every
 * side-out. The libero replaces the middle blocker in the back row (zones 5 and 6 always,
 * zone 1 only when we receive: when we serve, the middle in zone 1 serves himself). With two
 * liberos, the reception libero plays when we receive and the defence libero when we serve.
 */
final class RotationTimeline
{
    public function __construct(private readonly RallyBuilder $rallies) {}

    /**
     * @return array{players: array<int, array{n: int|null, name: string, libero: bool}>, rallies: list<array<string, mixed>>}
     */
    public function build(Game $game): array
    {
        $game->loadMissing(['sets.lineups.player', 'rallies']);
        $times = $game->rallies->mapWithKeys(fn (GameRally $r) => [$r->game_set_id.':'.$r->number => $r->video_time]);
        $setIds = $game->sets->pluck('id', 'number');
        $sets = $game->sets->keyBy('number');

        $liberos = Player::query()->whereIn('id', $game->sets->flatMap(fn (GameSet $s) => [$s->libero_reception_id, $s->libero_defense_id])->filter()->unique())
            ->get()->keyBy('id');
        $players = [];
        $out = [];

        foreach ($this->rallies->build($game, $game->actions()->get()) as $rally) {
            /** @var GameSet|null $set */
            $set = $sets->get($rally->set);
            $t = $rally->videoTime() ?? $times->get($setIds->get($rally->set).':'.$rally->number);

            if ($set === null || $rally->rotation === null || $t === null) {
                continue;
            }

            $libero = self::liberoId($set, $rally->server);
            $zones = self::zones($set, $rally->rotation, $rally->server, $libero !== null ? $liberos->get($libero) : null);

            foreach ($zones as $player) {
                $players[$player->id] ??= ['n' => $player->number, 'name' => $player->short_name, 'libero' => $player->isLibero()];
            }

            $out[] = [
                't' => (float) $t,
                'set' => $rally->set,
                'rally' => $rally->number,
                'rot' => $rally->rotation,
                'serve' => $rally->server,
                'us' => $rally->scoreUs,
                'them' => $rally->scoreThem,
                'zones' => array_map(fn (Player $p) => $p->id, $zones),
            ];
        }

        usort($out, fn (array $a, array $b) => $a['t'] <=> $b['t']);

        return ['players' => $players, 'rallies' => $out];
    }

    /**
     * Players in zones 1..6 for a rotation (1 = the starting lineup).
     *
     * @return array<int, Player> zone => player
     */
    public static function zones(GameSet $set, int $rotation, ?string $server, ?Player $libero = null): array
    {
        $byPosition = $set->lineups->whereNotNull('rotation_position')->keyBy('rotation_position');
        $zones = [];

        for ($zone = 1; $zone <= 6; $zone++) {
            $position = (($zone - 1 + $rotation - 1) % 6) + 1;
            $player = $byPosition->get($position)?->player;

            if ($player === null) {
                continue;
            }

            $backRow = in_array($zone, [5, 6], true) || ($zone === 1 && $server !== Rally::US);

            if ($libero !== null && $backRow && $player->position === Position::Middle) {
                $player = $libero;
            }

            $zones[$zone] = $player;
        }

        return $zones;
    }

    /** The libero on the court: the reception one when we receive, the defence one when we serve. */
    public static function liberoId(GameSet $set, ?string $server): ?int
    {
        return $server === Rally::US
            ? ($set->libero_defense_id ?? $set->libero_reception_id)
            : ($set->libero_reception_id ?? $set->libero_defense_id);
    }
}
