<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameRally;
use App\Models\GameSet;
use Illuminate\Support\Facades\DB;

/**
 * The recording was trimmed (or something was added at its start) on YouTube: every
 * moment stored for the match moves by the same number of seconds, so clicks still
 * land on the right touch and the analyst's corrections are kept.
 *
 * Negative = earlier (the warm-up was cut). Moments that fall before the new start:
 * touches and rallies lose their time (they stay, as "no time"), court frames are removed,
 * set starts go to 0.
 */
class VideoTimeShifter
{
    /** Above any real video time: frames are parked here so the unique (game, source, t) index never clashes. */
    private const PARK = 1_000_000;

    /**
     * @return array{actions: int, frames: int, untimed: int}
     */
    public function shift(Game $game, float $seconds): array
    {
        $seconds = round($seconds, 2);

        if ($seconds === 0.0) {
            return ['actions' => 0, 'frames' => 0, 'untimed' => 0];
        }

        return DB::transaction(function () use ($game, $seconds) {
            $actions = $game->actions()->whereNotNull('video_time')
                ->update(['video_time' => DB::raw('video_time + '.$seconds)]);
            $untimed = $game->actions()->where('video_time', '<', 0)->update(['video_time' => null]);

            GameRally::query()->where('game_id', $game->id)->whereNotNull('video_time')
                ->update(['video_time' => DB::raw('video_time + '.$seconds)]);
            GameRally::query()->where('game_id', $game->id)->where('video_time', '<', 0)->update(['video_time' => null]);

            GameSet::query()->where('game_id', $game->id)->whereNotNull('video_start')->update(['video_start' => DB::raw('video_start + '.$seconds)]);
            GameSet::query()->where('game_id', $game->id)->where('video_start', '<', 0)->update(['video_start' => 0]);

            $game->frames()->where('t', '<', -$seconds)->delete();
            $frames = $game->frames()->update(['t' => DB::raw('t + '.($seconds + self::PARK))]);
            $game->frames()->update(['t' => DB::raw('t - '.self::PARK)]);

            return ['actions' => $actions, 'frames' => $frames, 'untimed' => $untimed];
        });
    }
}
