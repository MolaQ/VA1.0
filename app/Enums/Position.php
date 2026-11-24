<?php

namespace App\Enums;

enum Position: string
{
    case Setter = 'setter';
    case Outside = 'outside';
    case Opposite = 'opposite';
    case Middle = 'middle';
    case Libero = 'libero';

    public function label(): string
    {
        return match ($this) {
            self::Setter => __('Setter'),
            self::Outside => __('Outside hitter'),
            self::Opposite => __('Opposite'),
            self::Middle => __('Middle blocker'),
            self::Libero => __('Libero'),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Setter => __('position.short.setter'),
            self::Outside => __('position.short.outside'),
            self::Opposite => __('position.short.opposite'),
            self::Middle => __('position.short.middle'),
            self::Libero => __('position.short.libero'),
        };
    }
}
