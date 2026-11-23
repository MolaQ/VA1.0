<?php

namespace App\Stats\Report;

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\GameAction;
use Illuminate\Support\Collection;

/**
 * One reconstructed rally: who served, who won, our rotation and our touches in order.
 */
final class Rally
{
    public const US = 'us';

    public const THEM = 'them';

    /** Who served: us / them / null (unknown) */
    public ?string $server = null;

    /** Who won: us / them / null (unknown) */
    public ?string $winner = null;

    /** Rotation 1..6 = which starting-lineup position stands in zone I (null without a lineup) */
    public ?int $rotation = null;

    public int $scoreUs = 0;

    public int $scoreThem = 0;

    /**
     * @param  Collection<int, GameAction>  $actions  our touches, in playing order
     */
    public function __construct(
        public readonly int $set,
        public readonly int $number,
        public readonly Collection $actions,
    ) {}

    /** We received serve: points won here are side-outs. */
    public function isSideOut(): bool
    {
        return $this->server === self::THEM;
    }

    /** We served: points won here are break points. */
    public function isBreakPoint(): bool
    {
        return $this->server === self::US;
    }

    public function first(Skill $skill): ?GameAction
    {
        return $this->actions->first(fn (GameAction $a) => $a->skill === $skill);
    }

    /**
     * First attack after our reception: the side-out attack ("first ball").
     */
    public function firstAttack(): ?GameAction
    {
        if (! $this->isSideOut()) {
            return null;
        }

        $reception = $this->first(Skill::Reception);
        $after = $reception ? $this->actions->skipUntil(fn (GameAction $a) => $a->is($reception)) : $this->actions;

        return $after->first(fn (GameAction $a) => $a->skill === Skill::Attack);
    }

    /**
     * Winner implied by our own touches: our point (#) or our error (=, attack/block "/").
     */
    public function inferredWinner(): ?string
    {
        foreach ($this->actions->reverse() as $a) {
            if ($a->grade === Grade::Perfect && in_array($a->skill, [Skill::Serve, Skill::Attack, Skill::Block], true)) {
                return self::US;
            }

            if ($a->grade === Grade::Error
                || ($a->grade === Grade::Slash && in_array($a->skill, [Skill::Attack, Skill::Block], true))) {
                return self::THEM;
            }
        }

        // The analyzer flags the outcome it read from the scoreboard on its actions.
        foreach ($this->actions as $a) {
            $won = $a->meta['rally_won'] ?? null;

            if (is_bool($won)) {
                return $won ? self::US : self::THEM;
            }
        }

        return null;
    }

    public function inferredServer(): ?string
    {
        if ($this->first(Skill::Serve)) {
            return self::US;
        }

        return $this->first(Skill::Reception) ? self::THEM : null;
    }

    /** Point won without any point-scoring touch of ours: the opponent's error. */
    public function wonByOpponentError(): bool
    {
        return $this->winner === self::US && ! $this->actions->contains(fn (GameAction $a) => $a->grade === Grade::Perfect
            && in_array($a->skill, [Skill::Serve, Skill::Attack, Skill::Block], true));
    }

    /** Point lost without an error of ours: the opponent simply won it. */
    public function lostToOpponentWinner(): bool
    {
        return $this->winner === self::THEM && ! $this->actions->contains(fn (GameAction $a) => $a->grade === Grade::Error
            || ($a->grade === Grade::Slash && in_array($a->skill, [Skill::Attack, Skill::Block], true)));
    }

    public function videoTime(): ?float
    {
        return $this->actions->first(fn (GameAction $a) => $a->video_time !== null)?->video_time;
    }
}
