<?php

namespace App\Enums;

enum Skill: string
{
    case Serve = 'serve';
    case Reception = 'reception';
    case Attack = 'attack';
    case Block = 'block';
    case Dig = 'dig';
    case Set = 'set';
    case Freeball = 'freeball';

    public function label(): string
    {
        return match ($this) {
            self::Serve => __('Serve'),
            self::Reception => __('Reception'),
            self::Attack => __('Attack'),
            self::Block => __('Block'),
            self::Dig => __('Dig'),
            self::Set => __('Set (skill)'),
            self::Freeball => __('Free ball'),
        };
    }

    /**
     * Single-letter code used by DataVolley-style scouting (S, R, A, B, D, E, F).
     */
    public function code(): string
    {
        return match ($this) {
            self::Serve => 'S',
            self::Reception => 'R',
            self::Attack => 'A',
            self::Block => 'B',
            self::Dig => 'D',
            self::Set => 'E',
            self::Freeball => 'F',
        };
    }
}
