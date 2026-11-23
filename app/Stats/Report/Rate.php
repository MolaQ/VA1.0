<?php

namespace App\Stats\Report;

/**
 * "won out of total" counter, e.g. side-out 14/22.
 */
final class Rate
{
    public int $won = 0;

    public int $total = 0;

    public function add(bool $won): void
    {
        $this->total++;
        $this->won += $won ? 1 : 0;
    }

    public function lost(): int
    {
        return $this->total - $this->won;
    }

    public function ratio(): ?float
    {
        return $this->total > 0 ? $this->won / $this->total : null;
    }
}
