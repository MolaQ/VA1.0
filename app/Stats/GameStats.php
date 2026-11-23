<?php

namespace App\Stats;

use Illuminate\Support\Collection;

final readonly class GameStats
{
    /**
     * @param  Collection<int, PlayerStatLine>  $lines
     * @param  array<int, int>  $setNumbers
     */
    public function __construct(
        public Collection $lines,
        public PlayerStatLine $totals,
        public array $setNumbers,
    ) {}

    public function forPlayer(int $playerId): ?PlayerStatLine
    {
        return $this->lines->first(fn (PlayerStatLine $line) => $line->player?->id === $playerId);
    }
}
