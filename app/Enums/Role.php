<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Trainer = 'trainer';
    case Player = 'player';
    case Parent = 'parent';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Trainer => __('Coach'),
            self::Player => __('Player'),
            self::Parent => __('Parent'),
        };
    }

    /**
     * Staff roles manage the club's data (games, roster, analysis).
     */
    public function isStaff(): bool
    {
        return in_array($this, [self::Admin, self::Trainer], true);
    }
}
