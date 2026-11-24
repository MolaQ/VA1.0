<?php

namespace App\Services;

use App\Models\GameAction;
use Illuminate\Support\Collection;

final readonly class IngestResult
{
    /**
     * @param  Collection<int, GameAction>  $actions
     * @param  array<int, array{index: int, message: string}>  $skipped
     */
    public function __construct(
        public Collection $actions,
        public int $created,
        public int $updated,
        public array $skipped = [],
    ) {}
}
