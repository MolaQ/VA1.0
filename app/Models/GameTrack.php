<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Jersey number (and team) given by the analyst to one analyzer track: every frame of
 * that track shows the number, so one click labels a player for as long as it is tracked.
 *
 * @property int $id
 * @property int $game_id
 * @property int $track
 * @property string|null $team
 * @property int|null $number
 */
#[Fillable(['game_id', 'track', 'team', 'number'])]
class GameTrack extends Model
{
    //
}
