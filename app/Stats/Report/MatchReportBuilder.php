<?php

namespace App\Stats\Report;

use App\Enums\Grade;
use App\Enums\Skill;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Builds the match report (efficiency, tactics, setting, usefulness) from the raw actions.
 *
 * Manual coding and AI actions go through the same code: whatever the analyzer recognises
 * (serves today, receptions and attacks later) fills the same tables automatically.
 */
final class MatchReportBuilder
{
    public function __construct(private readonly RallyBuilder $rallies) {}

    public function build(Game $game, ?int $setNumber = null): MatchReport
    {
        $game->loadMissing(['sets.lineups', 'rallies']);

        $all = $game->actions()->with('set:id,number')->orderBy('video_time')->orderBy('id')->get();
        $setNumbers = $game->sets->pluck('number')->map(fn ($n) => (int) $n)->all();
        $report = new MatchReport($game, $setNumber, $setNumbers);

        $inScope = fn (?int $set) => $setNumber === null || $set === $setNumber;
        $actions = $all->filter(fn (GameAction $a) => $inScope($a->set?->number))->values();

        $this->players($report, $game, $all, $actions);
        $this->skills($report, $actions, $all);
        $this->zones($report, $actions);

        $report->rallies = $this->rallies->build($game, $all)
            ->filter(fn (Rally $r) => $inScope($r->set))->values();
        $this->tactics($report, $game);

        return $report;
    }

    /**
     * @param  Collection<int, GameAction>  $all
     * @param  Collection<int, GameAction>  $actions
     */
    private function players(MatchReport $report, Game $game, Collection $all, Collection $actions): void
    {
        $players = Player::query()
            ->forClub($game->club_id)
            ->where(fn ($q) => $q
                ->where('team_id', $game->team_id)
                ->orWhereIn('id', $all->pluck('player_id')->filter()->unique()))
            ->orderByRaw('number is null, number')
            ->orderBy('last_name')
            ->get();

        $report->playerIndex = $players->keyBy('id')->all();
        $lines = $players->mapWithKeys(fn (Player $p) => [$p->id => new PlayerReport($p)]);

        foreach ($game->sets as $set) {
            if ($report->setNumber !== null && (int) $set->number !== $report->setNumber) {
                continue;
            }

            foreach ($set->lineups as $lineup) {
                $line = $lines->get($lineup->player_id);

                if ($line !== null) {
                    $line->sets[(int) $set->number] = true;
                }
            }
        }

        foreach ($actions as $action) {
            $line = $action->player_id !== null ? $lines->get($action->player_id) : null;

            if ($line === null) {
                continue;
            }

            $line->skills[$action->skill->value]->add($action);

            if ($action->set !== null) {
                $line->sets[(int) $action->set->number] = true;
            }
        }

        $report->players = $lines->values();
    }

    /**
     * @param  Collection<int, GameAction>  $actions  in scope
     * @param  Collection<int, GameAction>  $all  whole match (for the set comparison)
     */
    private function skills(MatchReport $report, Collection $actions, Collection $all): void
    {
        foreach (MatchReport::SKILLS as $skill) {
            $report->team[$skill->value] = new SkillLine($skill);
        }

        foreach ($actions as $action) {
            $report->team[$action->skill->value]->add($action);
            $source = $action->source->value;
            $report->sources[$source] = ($report->sources[$source] ?? 0) + 1;
        }

        foreach ($report->setNumbers as $n) {
            foreach (MatchReport::SKILLS as $skill) {
                $report->bySet[$n][$skill->value] = new SkillLine($skill);
            }
        }

        foreach ($all as $action) {
            $n = $action->set?->number;

            if ($n !== null && isset($report->bySet[$n])) {
                $report->bySet[$n][$action->skill->value]->add($action);
            }
        }

        foreach ($actions->where('skill', Skill::Set)->whereNotNull('player_id') as $set) {
            $this->setter($report, $set->player_id)['set']->add($set);
        }
    }

    /**
     * @param  Collection<int, GameAction>  $actions
     */
    private function zones(MatchReport $report, Collection $actions): void
    {
        foreach ([...MatchReport::ATTACK_ZONES, 'none'] as $zone) {
            $report->attackByZone[$zone] = new SkillLine(Skill::Attack);
        }

        foreach ($actions as $a) {
            if ($a->skill === Skill::Attack) {
                $zone = $a->zone_from !== null && isset($report->attackByZone[$a->zone_from]) ? $a->zone_from : 'none';
                $report->attackByZone[$zone]->add($a);

                if ($a->zone_from !== null && $a->zone_to !== null) {
                    $report->attackDirections[$a->zone_from][$a->zone_to] = ($report->attackDirections[$a->zone_from][$a->zone_to] ?? 0) + 1;
                }
            }

            if ($a->skill === Skill::Serve && $a->zone_to !== null) {
                $report->serveTargets[$a->zone_to] = ($report->serveTargets[$a->zone_to] ?? 0) + 1;
            }
        }
    }

    private function tactics(MatchReport $report, Game $game): void
    {
        foreach (range(1, 6) as $r) {
            $report->rotations[$r] = ['so' => new Rate, 'bp' => new Rate, 'us' => 0, 'them' => 0, 'server' => null];
        }

        foreach ($report->setNumbers as $n) {
            $report->setPhases[$n] = ['so' => new Rate, 'bp' => new Rate, 'us' => 0, 'them' => 0];
        }

        foreach (['#', '+', '!', '-', '/', '='] as $g) {
            $report->receptionToSideOut[$g] = ['so' => new Rate, 'attack' => new SkillLine(Skill::Attack)];
            $report->serveToBreak[$g] = new Rate;
            $report->attackAfterSet[$g] = new SkillLine(Skill::Attack);
        }

        $report->attackByPhase = ['sideout' => new SkillLine(Skill::Attack), 'transition' => new SkillLine(Skill::Attack)];

        foreach (MatchReport::RECEPTION_BUCKETS as $bucket) {
            $report->distributionByZone[$bucket] = [];
            $report->distributionByAttacker[$bucket] = [];
            $report->distributionOutcome[$bucket] = [];
        }

        foreach ($report->rallies as $rally) {
            $this->phase($report, $rally);
            $this->pointOrigin($report, $rally);
            $this->attacks($report, $rally);
        }

        $this->rotationServers($report, $game);
    }

    private function phase(MatchReport $report, Rally $rally): void
    {
        if ($rally->winner === null) {
            return;
        }

        $won = $rally->winner === Rally::US;
        $set = &$report->setPhases[$rally->set];
        $rot = $rally->rotation !== null ? $rally->rotation : null;

        $set[$won ? 'us' : 'them']++;

        if ($rot !== null) {
            $report->rotations[$rot][$won ? 'us' : 'them']++;
        }

        if ($rally->isSideOut()) {
            $report->sideOut->add($won);
            $set['so']->add($won);

            if ($rot !== null) {
                $report->rotations[$rot]['so']->add($won);
            }

            if ($reception = $rally->first(Skill::Reception)) {
                $report->receptionToSideOut[$reception->grade->value]['so']->add($won);
            }
        } elseif ($rally->isBreakPoint()) {
            $report->breakPoint->add($won);
            $set['bp']->add($won);

            if ($rot !== null) {
                $report->rotations[$rot]['bp']->add($won);
            }

            if ($serve = $rally->first(Skill::Serve)) {
                $report->serveToBreak[$serve->grade->value]->add($won);
            }
        }

        unset($set);
    }

    /** How the point was won or lost: the deciding touch, else the opponent. */
    private function pointOrigin(MatchReport $report, Rally $rally): void
    {
        if ($rally->winner === Rally::US) {
            $winner = $rally->actions->reverse()->first(fn (GameAction $a) => $a->grade === Grade::Perfect
                && in_array($a->skill, [Skill::Serve, Skill::Attack, Skill::Block], true));

            $key = match ($winner?->skill) {
                Skill::Serve => 'ace',
                Skill::Attack => 'attack',
                Skill::Block => 'block',
                default => 'opponent_error',
            };
            $report->pointsWon[$key]++;
        } elseif ($rally->winner === Rally::THEM) {
            $error = $rally->actions->reverse()->first(fn (GameAction $a) => $a->grade === Grade::Error
                || ($a->grade === Grade::Slash && in_array($a->skill, [Skill::Attack, Skill::Block], true)));

            $key = match (true) {
                $error === null => 'opponent',
                $error->skill === Skill::Attack && $error->grade === Grade::Slash => 'blocked',
                $error->skill === Skill::Serve => 'serve',
                $error->skill === Skill::Reception => 'reception',
                $error->skill === Skill::Attack => 'attack',
                default => 'other',
            };
            $report->pointsLost[$key]++;
        }
    }

    /**
     * Attacks of one rally: phase, setter, and where the ball was set after each reception quality.
     */
    private function attacks(MatchReport $report, Rally $rally): void
    {
        $first = $rally->firstAttack();
        $reception = $rally->first(Skill::Reception);
        $lastSet = null;

        foreach ($rally->actions as $a) {
            if ($a->skill === Skill::Set) {
                $lastSet = $a;

                continue;
            }

            if ($a->skill !== Skill::Attack) {
                continue;
            }

            $isFirst = $first !== null && $a->is($first);
            $report->attackByPhase[$isFirst ? 'sideout' : 'transition']->add($a);

            if ($isFirst) {
                $report->receptionToSideOut[$reception?->grade->value ?? '!']['attack']->add($a);
            }

            $bucket = match (true) {
                ! $isFirst => 'transition',
                $reception === null => null,
                $reception->grade === Grade::Perfect => 'perfect',
                $reception->grade === Grade::Positive => 'positive',
                default => 'poor',
            };

            if ($bucket !== null) {
                $zone = $a->zone_from ?? 'none';
                $report->distributionByZone[$bucket][$zone] = ($report->distributionByZone[$bucket][$zone] ?? 0) + 1;
                $report->distributionOutcome[$bucket][$zone] ??= new SkillLine(Skill::Attack);
                $report->distributionOutcome[$bucket][$zone]->add($a);

                if ($a->player_id !== null) {
                    $report->distributionByAttacker[$bucket][$a->player_id] = ($report->distributionByAttacker[$bucket][$a->player_id] ?? 0) + 1;
                }
            }

            if ($lastSet !== null) {
                $report->attackAfterSet[$lastSet->grade->value]->add($a);

                if ($lastSet->player_id !== null) {
                    $this->setter($report, $lastSet->player_id)['attack']->add($a);
                }

                $lastSet = null;
            }
        }
    }

    /** Who stands in zone I (serves) in each rotation, from the first set's starting lineup in scope. */
    private function rotationServers(MatchReport $report, Game $game): void
    {
        $set = $game->sets->first(fn ($s) => ($report->setNumber === null || (int) $s->number === $report->setNumber)
            && $s->lineups->whereNotNull('rotation_position')->isNotEmpty());

        if ($set === null) {
            return;
        }

        foreach ($set->lineups->whereNotNull('rotation_position') as $lineup) {
            $report->rotations[(int) $lineup->rotation_position]['server'] = $report->player($lineup->player_id);
        }
    }

    /**
     * @return array{player: ?Player, set: SkillLine, attack: SkillLine}
     */
    private function &setter(MatchReport $report, int $playerId): array
    {
        $report->setters[$playerId] ??= [
            'player' => $report->player($playerId),
            'set' => new SkillLine(Skill::Set),
            'attack' => new SkillLine(Skill::Attack),
        ];

        return $report->setters[$playerId];
    }
}
