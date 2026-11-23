<?php

namespace App\Stats;

use App\Enums\Grade;

/**
 * Reception grade from where the pass went, and serve grade from the opponent's reception.
 *
 * Points are in the receiving team's frame: x 0..9 left to right as the team faces the net,
 * y 0 = own end line, 6 = attack line, 9 = net.
 *
 * Scouting convention (DataVolley-style): a reception is graded by the options it leaves
 * the setter. Distance from the target is the measurable proxy:
 *   #  within ~1 m of the target (between zones 3 and 2, ~1 m off the net): every option, incl. the quick
 *   +  in front of the 3 m line, close enough for a set to the hitters, no reliable quick
 *   !  on the court behind the 3 m line: only a high ball to the wings
 *   -  outside the court: setter can barely play it
 *   /  over the net (overpass)
 * The analyst can always override; this is only the suggestion.
 */
final class PassQuality
{
    /** Target of the pass: between zones 3 and 2, 1 m from the net. */
    public const TARGET_X = 6.0;

    public const TARGET_Y = 8.0;

    public const PERFECT_RADIUS = 1.0;

    public const POSITIVE_RADIUS = 3.5;

    public const ATTACK_LINE_Y = 6.0;

    public const NET_Y = 9.0;

    public static function reception(float $x, float $y): Grade
    {
        if ($y > self::NET_Y) {
            return Grade::Slash;
        }

        $distance = hypot($x - self::TARGET_X, $y - self::TARGET_Y);

        return match (true) {
            $distance <= self::PERFECT_RADIUS => Grade::Perfect,
            $y >= self::ATTACK_LINE_Y && $distance <= self::POSITIVE_RADIUS => Grade::Positive,
            $x >= 0 && $x <= 9 && $y >= 0 => Grade::Neutral,
            default => Grade::Poor,
        };
    }

    /**
     * Serve grade that matches the opponent's reception (the two are coded as a pair):
     * ace / reception error = #, overpass or no attack = /, poor reception = +,
     * OK reception = !, perfect or positive reception = -.
     */
    public static function serveFromReception(Grade $reception): Grade
    {
        return match ($reception) {
            Grade::Error => Grade::Perfect,
            Grade::Slash => Grade::Slash,
            Grade::Poor => Grade::Positive,
            Grade::Neutral => Grade::Neutral,
            Grade::Positive, Grade::Perfect => Grade::Poor,
        };
    }

    /**
     * Zone 1..9 where a serve landed. The point is in the server's frame (y 9..18 is the
     * receiving half); the zone is numbered as the receiving team counts it:
     * back row 5 6 1, middle 7 8 9, front 4 3 2, left to right as the receivers face the net.
     */
    public static function serveZone(float $x, float $y): ?int
    {
        if ($x < 0 || $x > 9 || $y < 9 || $y > 18) {
            return null;
        }

        // seen from the server the receiving team's left is our right
        $col = min(2, (int) floor((9 - $x) / 3));
        $depth = 18 - $y; // distance from the receivers' end line

        return match (true) {
            $depth < 3 => [5, 6, 1][$col],
            $depth < 6 => [7, 8, 9][$col],
            default => [4, 3, 2][$col],
        };
    }
}
