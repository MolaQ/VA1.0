<?php

namespace App\Models;

use App\Enums\ActionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Players on the court at one moment of the video (2D court view under the video).
 *
 * Coordinates in metres as the camera sees the court: x 0..9 across, y 0..18 along
 * (0 = near end line, 9 = net). Facing `f` in degrees: 0 = towards the far end line,
 * 90 = towards x = 9 (clockwise seen from above).
 *
 * @property int $id
 * @property int $game_id
 * @property float $t
 * @property ActionSource $source
 * @property list<array{team: string, n: int|null, k: int|null, x: float, y: float, f: float|null}> $players
 */
#[Fillable(['game_id', 't', 'source', 'players', 'created_by'])]
class GameFrame extends Model
{
    public const MAX_PLAYERS = 16;

    protected function casts(): array
    {
        return ['t' => 'float', 'source' => ActionSource::class, 'players' => 'array'];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Keep only well-formed dots, rounded, inside a court with a run-off margin.
     *
     * @param  array<int, mixed>  $players
     * @return list<array{team: string, n: int|null, k: int|null, x: float, y: float, f: float|null}>
     */
    public static function clean(array $players): array
    {
        $out = [];

        foreach (array_slice($players, 0, self::MAX_PLAYERS) as $p) {
            if (! is_array($p) || ! is_numeric($p['x'] ?? null) || ! is_numeric($p['y'] ?? null)) {
                continue;
            }

            $n = $p['n'] ?? null;
            $k = $p['k'] ?? null;
            $f = $p['f'] ?? null;

            $out[] = [
                'team' => in_array($p['team'] ?? null, [GameRally::US, GameRally::THEM], true) ? $p['team'] : GameRally::US,
                'n' => is_numeric($n) && $n >= 0 && $n <= 99 ? (int) $n : null,
                'k' => is_numeric($k) && $k >= 0 ? (int) $k : null,
                'x' => round(max(-4, min(13, (float) $p['x'])), 2),
                'y' => round(max(-6, min(24, (float) $p['y'])), 2),
                'f' => is_numeric($f) ? round(fmod(fmod((float) $f, 360) + 360, 360), 0) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{t: float, src: string, p: list<array<string, mixed>>}
     */
    public function toClient(): array
    {
        return ['t' => $this->t, 'src' => $this->source->value, 'p' => $this->players];
    }
}
