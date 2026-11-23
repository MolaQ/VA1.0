<?php

namespace App\Enums;

/**
 * Standard volleyball scouting evaluation symbols.
 *
 *  #  perfect / point (ace, kill, block point, perfect reception)
 *  +  positive
 *  !  neutral / ok
 *  -  poor
 *  /  overpass-type outcome (attack: blocked, serve: opponent overpass, reception: overpass)
 *  =  error
 */
enum Grade: string
{
    case Perfect = '#';
    case Positive = '+';
    case Neutral = '!';
    case Poor = '-';
    case Slash = '/';
    case Error = '=';

    public function label(): string
    {
        return match ($this) {
            self::Perfect => __('Perfect / point'),
            self::Positive => __('Positive'),
            self::Neutral => __('Neutral'),
            self::Poor => __('Poor'),
            self::Slash => __('Overpass / blocked'),
            self::Error => __('Error'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Perfect, self::Positive => 'success',
            self::Neutral => 'neutral',
            self::Poor, self::Slash => 'warning',
            self::Error => 'danger',
        };
    }
}
