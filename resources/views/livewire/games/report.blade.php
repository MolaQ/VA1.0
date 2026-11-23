@use('App\Enums\Skill')
@use('App\Stats\PlayerStatLine')
@use('App\Stats\Report\MatchReport')
@use('App\Stats\Report\PlayerValue')
@php
    $pct = fn (?float $v) => PlayerStatLine::percent($v);
    $num = fn (?float $v, int $d = 1) => $v === null ? '–' : number_format($v, $d, ',', ' ');
    // "good" thresholds per skill: [success, efficiency]
    $good = [
        'serve' => [0.1, 0.1], 'reception' => [0.55, 0.35], 'attack' => [0.5, 0.35], 'block' => [0.3, 0.2],
        'dig' => [0.5, 0.3], 'set' => [0.6, 0.5], 'freeball' => [0.6, 0.5],
    ];
    $successLabel = [
        'serve' => __('Aces'), 'reception' => __('Positive (#+)'), 'attack' => __('Kills'), 'block' => __('Block points'),
        'dig' => __('Positive (#+)'), 'set' => __('Positive (#+)'), 'freeball' => __('Positive (#+)'),
    ];
    $grades = ['#', '+', '!', '-', '/', '='];
    $team = $report->team;
    $usedSkills = collect(MatchReport::SKILLS)->filter(fn (Skill $s) => $team[$s->value]->total() > 0)->values();
    $ranking = $report->ranking();
    $teamPoints = max(1, $report->players->sum(fn ($p) => $p->points()));
    $maxValue = max(1, $ranking->max(fn ($p) => abs($p->value())) ?? 1);
    $won = $game->isWon();
    $rallyCount = $report->rallies->count();
    $known = $report->knownRallies();
    $zoneName = fn ($z) => $z === 'none' ? __('No zone') : __('Zone').' '.$z;
@endphp
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4 animate-fade-up">
        <div class="min-w-0">
            <a href="{{ route('games.show', $game) }}" wire:navigate class="no-print mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-fg"><x-icon name="arrow-left" class="size-4" /> {{ __('Back to match') }}</a>
            <p class="text-xs font-semibold tracking-[0.18em] text-brand-400 uppercase">{{ __('Match report') }}</p>
            <h1 class="font-display text-3xl font-bold tracking-wide sm:text-4xl">{{ $game->team->name }} <span class="text-muted">vs</span> {{ $game->opponent }}</h1>
            <p class="mt-1 text-sm text-muted">
                {{ $game->played_at->translatedFormat('j F Y') }} · {{ $game->is_home ? __('Home') : __('Away') }}
                · {{ $report->setNumber ? __('Set').' '.$report->setNumber : __('Whole match') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="text-right">
                <p @class(['font-display text-4xl font-bold tabular leading-none', 'text-success-400' => $won === true, 'text-danger-400' => $won === false])>{{ $game->result() }}</p>
                <p class="mt-1 flex justify-end gap-1.5 text-xs text-muted tabular">
                    @foreach ($game->sets as $set)<span class="rounded bg-surface-3 px-1.5 py-0.5">{{ $set->score_us }}:{{ $set->score_them }}</span>@endforeach
                </p>
            </div>
            <x-ui.button class="no-print" size="sm" variant="secondary" icon="download" x-on:click="window.print()">{{ __('PDF / Print') }}</x-ui.button>
        </div>
    </div>

    {{-- Set filter + section nav --}}
    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-1 rounded-xl bg-surface-2 p-1 text-sm">
            <button wire:click="$set('setNumber', null)" @class(['rounded-lg px-3 py-1.5 font-semibold transition', 'bg-surface-3 text-fg shadow' => $report->setNumber === null, 'text-muted hover:text-fg' => $report->setNumber !== null])>{{ __('Match') }}</button>
            @foreach ($report->setNumbers as $n)
                <button wire:click="$set('setNumber', {{ $n }})" @class(['rounded-lg px-3 py-1.5 font-semibold transition', 'bg-surface-3 text-fg shadow' => $report->setNumber === $n, 'text-muted hover:text-fg' => $report->setNumber !== $n])>S{{ $n }}</button>
            @endforeach
        </div>
        <nav class="flex flex-wrap gap-1 text-sm">
            @foreach (['summary' => __('Summary'), 'skills' => __('Efficiency'), 'players' => __('Players'), 'setting' => __('Setting'), 'tactics' => __('Tactics'), 'value' => __('Usefulness')] as $id => $label)
                <a href="#{{ $id }}" class="rounded-lg px-3 py-1.5 font-semibold text-muted transition hover:bg-surface-2 hover:text-fg">{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    <p class="text-xs text-muted">
        {{ __('Data') }}: {{ array_sum($report->sources) }} {{ __('actions') }}
        @if ($report->sources['ai'] ?? 0)({{ $report->sources['ai'] }} AI, {{ ($report->sources['manual'] ?? 0) + ($report->sources['import'] ?? 0) }} {{ __('coded manually') }})@endif
        · {{ $rallyCount }} {{ __('rallies') }}, {{ $known }} {{ __('with a known winner') }}.
        {{ __('Everything is computed from the coded actions: AI and manual coding fill the same tables.') }}
    </p>

    <div wire:loading.class="opacity-60" wire:target="setNumber" class="space-y-6">

    {{-- ============================== SUMMARY ============================== --}}
    <section id="summary" class="scroll-mt-20 space-y-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <x-ui.stat :label="__('Side-out')" :value="$pct($report->sideOut->ratio())" :hint="$report->sideOut->won.'/'.$report->sideOut->total.' '.__('on opponent serve')" tone="brand" />
            <x-ui.stat :label="__('Break point')" :value="$pct($report->breakPoint->ratio())" :hint="$report->breakPoint->won.'/'.$report->breakPoint->total.' '.__('on our serve')" tone="energy" />
            <x-ui.stat :label="__('Attack success')" :value="$pct($team['attack']->success())" :hint="__('Efficiency').' '.$pct($team['attack']->efficiency())" tone="success" />
            <x-ui.stat :label="__('Positive reception')" :value="$pct($team['reception']->success())" :hint="__('Perfect').' '.$pct($team['reception']->perfect())" tone="brand" />
            <x-ui.stat :label="__('Serve efficiency')" :value="$pct($team['serve']->efficiency())" :hint="$team['serve']->count('#').' '.__('aces').', '.$team['serve']->errors().' '.__('errors')" tone="energy" />
            <x-ui.stat :label="__('Points / errors')" :value="collect($team)->sum(fn ($l) => $l->points()).' / '.collect($team)->sum(fn ($l) => $l->pointsLost())" :hint="__('direct points vs points given away')" tone="danger" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @php
                $wonTotal = max(1, array_sum($report->pointsWon));
                $lostTotal = max(1, array_sum($report->pointsLost));
                $wonLabels = ['ace' => __('Aces'), 'attack' => __('Attack kills'), 'block' => __('Block points'), 'opponent_error' => __('Opponent errors')];
                $lostLabels = ['serve' => __('Serve errors'), 'reception' => __('Reception errors (aces against)'), 'attack' => __('Attack errors'), 'blocked' => __('Attacks blocked'), 'other' => __('Other errors (net, dig, set)'), 'opponent' => __('Opponent winners')];
            @endphp
            <x-ui.card :title="__('How we scored')" :subtitle="array_sum($report->pointsWon).' '.__('points in rallies with a known winner')" padding="p-4">
                <ul class="space-y-2">
                    @foreach ($wonLabels as $k => $label)
                        <li class="grid grid-cols-[10rem_1fr_3rem] items-center gap-3 text-sm">
                            <span class="text-muted">{{ $label }}</span>
                            <span class="h-2.5 overflow-hidden rounded-full bg-surface-3"><span class="block h-full rounded-full bg-success-500" style="width: {{ round(100 * $report->pointsWon[$k] / $wonTotal) }}%"></span></span>
                            <span class="text-right font-semibold tabular">{{ $report->pointsWon[$k] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
            <x-ui.card :title="__('How we lost points')" :subtitle="array_sum($report->pointsLost).' '.__('points for the opponent')" padding="p-4">
                <ul class="space-y-2">
                    @foreach ($lostLabels as $k => $label)
                        <li class="grid grid-cols-[10rem_1fr_3rem] items-center gap-3 text-sm">
                            <span class="text-muted">{{ $label }}</span>
                            <span class="h-2.5 overflow-hidden rounded-full bg-surface-3"><span class="block h-full rounded-full bg-danger-500" style="width: {{ round(100 * $report->pointsLost[$k] / $lostTotal) }}%"></span></span>
                            <span class="text-right font-semibold tabular">{{ $report->pointsLost[$k] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </section>

    {{-- ============================== EFFICIENCY BY SKILL ============================== --}}
    <section id="skills" class="scroll-mt-20 space-y-4">
        <x-ui.card :title="__('Efficiency and success by skill')" :subtitle="__('Success = share of the best outcome. Efficiency = (good − outcomes that cost the point) / total.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th class="!text-left">{{ __('Skill') }}</th>
                            <th class="group-start">{{ __('Total') }}</th>
                            @foreach ($grades as $g)<th>{{ $g }}</th>@endforeach
                            <th class="group-start">{{ __('Success') }}</th>
                            <th>{{ __('Efficiency') }}</th>
                            <th>{{ __('Errors') }}</th>
                            <th class="!text-left">{{ __('Success counts') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($usedSkills as $s)
                            @php $l = $team[$s->value]; @endphp
                            <tr>
                                <td class="!text-left font-semibold">{{ $s->label() }}</td>
                                <td class="group-start font-semibold">{{ $l->total() }}</td>
                                @foreach ($grades as $g)<td @class(['text-subtle' => $l->count($g) === 0])>{{ $l->count($g) }}</td>@endforeach
                                <td class="group-start"><x-pct :value="$l->success()" :good="$good[$s->value][0]" /></td>
                                <td><x-pct :value="$l->efficiency()" :good="$good[$s->value][1]" /></td>
                                <td>{{ $pct($l->errorRate()) }}</td>
                                <td class="!text-left text-xs text-muted">{{ $successLabel[$s->value] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="py-8 text-muted">{{ __('No recorded actions.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @if (count($report->setNumbers) > 0)
            <x-ui.card :title="__('Set by set')" :subtitle="__('The same numbers for each set: where the match was won or lost.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="!text-left">Set</th>
                                <th>{{ __('Score') }}</th>
                                <th class="group-start">{{ __('Side-out') }}</th>
                                <th>{{ __('Break point') }}</th>
                                <th class="group-start" title="{{ __('Serve efficiency') }}">{{ __('Serve') }} Eff</th>
                                <th title="{{ __('Positive reception') }}">{{ __('Rec.') }} #+</th>
                                <th title="{{ __('Attack success') }}">{{ __('Attack') }} #</th>
                                <th title="{{ __('Attack efficiency') }}">{{ __('Attack') }} Eff</th>
                                <th>{{ __('Block points') }}</th>
                                <th class="group-start">{{ __('Errors') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->setNumbers as $n)
                                @php
                                    $sl = $report->bySet[$n];
                                    $ph = $report->setPhases[$n];
                                    $set = $game->sets->firstWhere('number', $n);
                                @endphp
                                <tr @class(['bg-brand-500/5' => $report->setNumber === $n])>
                                    <td class="!text-left font-semibold">S{{ $n }}</td>
                                    <td @class(['font-semibold tabular', 'text-success-400' => $set && $set->score_us > $set->score_them, 'text-danger-400' => $set && $set->score_us < $set->score_them])>{{ $set?->score_us }}:{{ $set?->score_them }}</td>
                                    <td class="group-start"><x-pct :value="$ph['so']->ratio()" :good="0.6" /> <span class="text-xs text-subtle">{{ $ph['so']->won }}/{{ $ph['so']->total }}</span></td>
                                    <td><x-pct :value="$ph['bp']->ratio()" :good="0.4" /> <span class="text-xs text-subtle">{{ $ph['bp']->won }}/{{ $ph['bp']->total }}</span></td>
                                    <td class="group-start"><x-pct :value="$sl['serve']->efficiency()" :good="0.1" /></td>
                                    <td><x-pct :value="$sl['reception']->success()" :good="0.55" /></td>
                                    <td><x-pct :value="$sl['attack']->success()" :good="0.5" /></td>
                                    <td><x-pct :value="$sl['attack']->efficiency()" :good="0.35" /></td>
                                    <td>{{ $sl['block']->points() }}</td>
                                    <td class="group-start">{{ collect($sl)->sum(fn ($l) => $l->pointsLost()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </section>

    {{-- ============================== PLAYERS ============================== --}}
    <section id="players" class="print-break scroll-mt-20">
        <x-ui.card :title="__('Players: efficiency and success')" :subtitle="__('Per skill: touches, success % and efficiency %. Use the set filter above for one set.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                @php $cols = $usedSkills->reject(fn ($s) => $s === Skill::Freeball); @endphp
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="!text-left">{{ __('No.') }}</th>
                            <th rowspan="2" class="!text-left">{{ __('Player') }}</th>
                            <th rowspan="2" class="group-start">{{ __('Sets') }}</th>
                            <th colspan="3" class="group-start">{{ __('Points') }}</th>
                            @foreach ($cols as $s)<th colspan="3" class="group-start">{{ $s->label() }}</th>@endforeach
                        </tr>
                        <tr>
                            <th class="group-start">{{ __('Pts') }}</th><th title="{{ __('Points given away') }}">{{ __('Err') }}</th><th>+/−</th>
                            @foreach ($cols as $s)
                                <th class="group-start">{{ __('Total') }}</th><th title="{{ __('Success') }}">{{ __('Succ.') }}</th><th title="{{ __('Efficiency') }}">Eff</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report->players->filter(fn ($p) => $p->touches() > 0) as $p)
                            <tr wire:key="pr-{{ $p->player?->id }}">
                                <td class="!text-left font-display text-base font-bold text-muted">{{ $p->player?->number }}</td>
                                <td class="!text-left font-semibold">{{ $p->player?->full_name }}</td>
                                <td class="group-start text-muted">{{ $p->setsPlayed() }}</td>
                                <td class="group-start font-semibold">{{ $p->points() }}</td>
                                <td>{{ $p->pointsLost() }}</td>
                                <td @class(['text-success-400' => $p->plusMinus() > 0, 'text-danger-400' => $p->plusMinus() < 0])>{{ $p->plusMinus() }}</td>
                                @foreach ($cols as $s)
                                    @php $l = $p->skill($s); @endphp
                                    <td class="group-start">{{ $l->total() ?: '' }}</td>
                                    <td>@if ($l->total())<x-pct :value="$l->success()" :good="$good[$s->value][0]" />@endif</td>
                                    <td>@if ($l->total())<x-pct :value="$l->efficiency()" :good="$good[$s->value][1]" />@endif</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="30" class="py-8 text-muted">{{ __('No actions assigned to players yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </section>

    {{-- ============================== SETTING ============================== --}}
    <section id="setting" class="print-break scroll-mt-20 space-y-4">
        @php
            $buckets = MatchReport::RECEPTION_BUCKETS;
            $zones = [...MatchReport::ATTACK_ZONES, 'none'];
        @endphp
        <x-ui.card :title="__('Setting distribution')" :subtitle="__('Where the ball went (attack zone) depending on the reception. Each cell: share of attacks and kill %.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th class="!text-left">{{ __('Situation') }}</th>
                            <th class="group-start">{{ __('Attacks') }}</th>
                            @foreach ($zones as $z)<th @class(['group-start' => $loop->first])>{{ $z === 'none' ? '?' : $z }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buckets as $b)
                            @php $total = array_sum($report->distributionByZone[$b]); @endphp
                            <tr>
                                <td class="!text-left font-semibold">{{ MatchReport::bucketLabel($b) }}</td>
                                <td class="group-start font-semibold">{{ $total }}</td>
                                @foreach ($zones as $z)
                                    @php $n = $report->distributionByZone[$b][$z] ?? 0; $out = $report->distributionOutcome[$b][$z] ?? null; @endphp
                                    <td @class(['group-start' => $loop->first])>
                                        @if ($n)
                                            <span class="font-semibold">{{ round(100 * $n / max(1, $total)) }}%</span>
                                            <span class="block text-[10px] text-muted">{{ $n }} · # {{ $pct($out?->success()) }}</span>
                                        @else
                                            <span class="text-subtle">·</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @unless ($report->hasZones())
                    <p class="mt-3 text-xs text-muted">{{ __('No attack zones coded yet. Pick the zone when coding an attack (or press its number) to see the distribution.') }}</p>
                @endunless
            </div>
        </x-ui.card>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card :title="__('Distribution by attacker')" :subtitle="__('Who got the ball, by situation.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    @php
                        $attackers = collect($report->distributionByAttacker)->flatMap(fn ($row) => array_keys($row))->unique()
                            ->sortByDesc(fn ($id) => collect($report->distributionByAttacker)->sum(fn ($row) => $row[$id] ?? 0))->values();
                    @endphp
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="!text-left">{{ __('Player') }}</th>
                                @foreach ($buckets as $b)<th class="group-start" title="{{ MatchReport::bucketLabel($b) }}">{{ ['perfect' => '#', 'positive' => '+', 'poor' => '! - /', 'transition' => __('Trans.')][$b] }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($attackers as $id)
                                @php $pl = $report->player($id); @endphp
                                <tr>
                                    <td class="!text-left"><b>{{ $pl?->numberLabel() }}</b> {{ $pl && ! $pl->isAnonymous() ? $pl->short_name : '' }}</td>
                                    @foreach ($buckets as $b)
                                        @php $n = $report->distributionByAttacker[$b][$id] ?? 0; $t = array_sum($report->distributionByAttacker[$b]); @endphp
                                        <td class="group-start">@if ($n)<span class="font-semibold">{{ round(100 * $n / max(1, $t)) }}%</span> <span class="text-[10px] text-muted">({{ $n }})</span>@else<span class="text-subtle">·</span>@endif</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-muted">{{ __('No attacks in rallies yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Setting quality')" :subtitle="__('Set grade and what the attackers did with it.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="!text-left">{{ __('Setter') }}</th>
                                <th class="group-start">{{ __('Sets') }}</th>
                                <th>#</th><th>+</th><th>{{ __('Succ.') }}</th><th>Eff</th>
                                <th class="group-start" title="{{ __('Attack kill % after this setter') }}">{{ __('Attack') }} #</th>
                                <th title="{{ __('Attack efficiency after this setter') }}">{{ __('Attack') }} Eff</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report->setters as $row)
                                <tr>
                                    <td class="!text-left"><b>{{ $row['player']?->numberLabel() }}</b> {{ $row['player'] && ! $row['player']->isAnonymous() ? $row['player']->short_name : '' }}</td>
                                    <td class="group-start font-semibold">{{ $row['set']->total() }}</td>
                                    <td>{{ $row['set']->count('#') }}</td>
                                    <td>{{ $row['set']->count('+') }}</td>
                                    <td><x-pct :value="$row['set']->success()" :good="0.6" /></td>
                                    <td><x-pct :value="$row['set']->efficiency()" :good="0.5" /></td>
                                    <td class="group-start"><x-pct :value="$row['attack']->success()" :good="0.5" /> <span class="text-[10px] text-muted">{{ $row['attack']->total() }}</span></td>
                                    <td><x-pct :value="$row['attack']->efficiency()" :good="0.35" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-6 text-muted">{{ __('No sets coded yet. Code the setter\'s touch (skill "Set", key E) to rate setting.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @php $afterSet = collect($report->attackAfterSet)->filter(fn ($l) => $l->total() > 0); @endphp
                    @if ($afterSet->isNotEmpty())
                        <p class="label mt-4">{{ __('Attack after a set graded') }}</p>
                        <div class="flex flex-wrap gap-2 text-sm">
                            @foreach ($afterSet as $g => $l)
                                <span class="rounded-lg bg-surface-2 px-2.5 py-1"><b>{{ $g }}</b> → {{ __('kill') }} {{ $pct($l->success()) }} · eff {{ $pct($l->efficiency()) }} <span class="text-xs text-muted">({{ $l->total() }})</span></span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </section>

    {{-- ============================== TACTICS ============================== --}}
    <section id="tactics" class="print-break scroll-mt-20 space-y-4">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card :title="__('Rotations')" :subtitle="__('Rotation N: starting position N stands in zone I. Side-out on their serve, break point on ours.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    @if ($report->hasRotations())
                        <table class="stats-table">
                            <thead>
                                <tr>
                                    <th class="!text-left">{{ __('Rotation') }}</th>
                                    <th class="!text-left">{{ __('Server') }}</th>
                                    <th class="group-start">{{ __('Side-out') }}</th>
                                    <th>{{ __('Break point') }}</th>
                                    <th class="group-start">{{ __('Points') }}</th>
                                    <th>+/−</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report->rotations as $r => $row)
                                    @php $diff = $row['us'] - $row['them']; @endphp
                                    <tr>
                                        <td class="!text-left font-display text-base font-bold">{{ ['', 'I', 'II', 'III', 'IV', 'V', 'VI'][$r] }}</td>
                                        <td class="!text-left">{{ $row['server']?->numberLabel() }} {{ $row['server'] && ! $row['server']->isAnonymous() ? $row['server']->short_name : '' }}</td>
                                        <td class="group-start"><x-pct :value="$row['so']->ratio()" :good="0.6" /> <span class="text-xs text-subtle">{{ $row['so']->won }}/{{ $row['so']->total }}</span></td>
                                        <td><x-pct :value="$row['bp']->ratio()" :good="0.4" /> <span class="text-xs text-subtle">{{ $row['bp']->won }}/{{ $row['bp']->total }}</span></td>
                                        <td class="group-start tabular">{{ $row['us'] }}:{{ $row['them'] }}</td>
                                        <td @class(['font-semibold', 'text-success-400' => $diff > 0, 'text-danger-400' => $diff < 0])>{{ $diff > 0 ? '+' : '' }}{{ $diff }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="py-6 text-center text-sm text-muted">{{ __('Enter the starting lineups on the match page to see rotations.') }}</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Reception → side-out')" :subtitle="__('How the reception quality turns into points. First attack = attack right after our reception.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="!text-left">{{ __('Reception') }}</th>
                                <th class="group-start">{{ __('Side-out') }}</th>
                                <th class="group-start">{{ __('First attack') }}</th>
                                <th>{{ __('Kill') }}</th>
                                <th>Eff</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->receptionToSideOut as $g => $row)
                                @continue($row['so']->total === 0 && $row['attack']->total() === 0)
                                <tr>
                                    <td class="!text-left font-display text-base font-bold">{{ $g }}</td>
                                    <td class="group-start"><x-pct :value="$row['so']->ratio()" :good="0.6" /> <span class="text-xs text-subtle">{{ $row['so']->won }}/{{ $row['so']->total }}</span></td>
                                    <td class="group-start">{{ $row['attack']->total() }}</td>
                                    <td><x-pct :value="$row['attack']->success()" :good="0.5" /></td>
                                    <td><x-pct :value="$row['attack']->efficiency()" :good="0.35" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="label mt-4">{{ __('Serve pressure → break point') }}</p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        @forelse (collect($report->serveToBreak)->filter(fn ($r) => $r->total > 0) as $g => $r)
                            <span class="rounded-lg bg-surface-2 px-2.5 py-1"><b>{{ $g }}</b> → BP {{ $pct($r->ratio()) }} <span class="text-xs text-muted">({{ $r->won }}/{{ $r->total }})</span></span>
                        @empty
                            <span class="text-muted">{{ __('No serves with a known rally result yet.') }}</span>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card :title="__('Attack by phase and zone')" :subtitle="__('Side-out attack (after reception) vs transition (counter-attack), and each attack zone.')" padding="p-0 sm:p-0">
                <div class="overflow-x-auto p-3 sm:p-4">
                    <table class="stats-table">
                        <thead>
                            <tr>
                                <th class="!text-left"></th>
                                <th class="group-start">{{ __('Total') }}</th>
                                <th>#</th><th>=</th><th>/</th>
                                <th class="group-start">{{ __('Kill') }}</th>
                                <th>Eff</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (['sideout' => __('Side-out attack'), 'transition' => __('Transition attack')] as $k => $label)
                                @php $l = $report->attackByPhase[$k]; @endphp
                                <tr>
                                    <td class="!text-left font-semibold">{{ $label }}</td>
                                    <td class="group-start">{{ $l->total() }}</td>
                                    <td>{{ $l->count('#') }}</td><td>{{ $l->count('=') }}</td><td>{{ $l->count('/') }}</td>
                                    <td class="group-start"><x-pct :value="$l->success()" :good="0.5" /></td>
                                    <td><x-pct :value="$l->efficiency()" :good="0.35" /></td>
                                </tr>
                            @endforeach
                            @foreach ($report->attackByZone as $z => $l)
                                @continue($l->total() === 0)
                                <tr>
                                    <td class="!text-left">{{ $zoneName($z) }}</td>
                                    <td class="group-start">{{ $l->total() }}</td>
                                    <td>{{ $l->count('#') }}</td><td>{{ $l->count('=') }}</td><td>{{ $l->count('/') }}</td>
                                    <td class="group-start"><x-pct :value="$l->success()" :good="0.5" /></td>
                                    <td><x-pct :value="$l->efficiency()" :good="0.35" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Attack directions and serve targets')" :subtitle="__('Opponent court seen from our side. Darker = more balls.')" padding="p-4">
                @php
                    $landing = [];
                    foreach ($report->attackDirections as $from => $to) {
                        foreach ($to as $z => $n) { $landing[$z] = ($landing[$z] ?? 0) + $n; }
                    }
                    $courts = [__('Attack landing zones') => $landing, __('Serve targets') => $report->serveTargets];
                @endphp
                <div class="grid grid-cols-2 gap-4">
                    @foreach ($courts as $title => $counts)
                        @php $max = max(1, ...array_values($counts ?: [0])); $sum = array_sum($counts); @endphp
                        <div>
                            <p class="label">{{ $title }} <span class="text-subtle">({{ $sum }})</span></p>
                            <div class="grid grid-cols-3 gap-1 rounded-xl border-2 border-line-strong p-1">
                                @foreach ([4, 3, 2, 7, 8, 9, 5, 6, 1] as $z)
                                    @php $n = $counts[$z] ?? 0; @endphp
                                    <div class="grid aspect-square place-items-center rounded-lg text-center" style="background: rgb(59 130 246 / {{ $n ? 0.12 + 0.75 * $n / $max : 0.04 }})">
                                        <div><span class="block text-[10px] text-muted">{{ $z }}</span><span class="font-semibold tabular">{{ $n ?: '' }}</span></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($report->attackDirections !== [])
                    <p class="label mt-4">{{ __('Most used directions') }}</p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        @foreach (collect($report->attackDirections)->flatMap(fn ($to, $from) => collect($to)->map(fn ($n, $z) => ['k' => $from.' → '.$z, 'n' => $n]))->sortByDesc('n')->take(6) as $d)
                            <span class="rounded-lg bg-surface-2 px-2.5 py-1 tabular">{{ $d['k'] }} <span class="text-xs text-muted">× {{ $d['n'] }}</span></span>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>
    </section>

    {{-- ============================== USEFULNESS ============================== --}}
    <section id="value" class="print-break scroll-mt-20">
        <x-ui.card :title="__('Player usefulness')" :subtitle="__('Value of every touch added up: a point is +1, an error −1, the grades in between weigh how much they helped. Ranked by value per set.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th class="!text-left">#</th>
                            <th class="!text-left">{{ __('Player') }}</th>
                            <th class="group-start">{{ __('Value') }}</th>
                            <th>{{ __('Per set') }}</th>
                            <th title="{{ __('Value per touch') }}">{{ __('Per touch') }}</th>
                            <th class="!text-left" style="min-width: 10rem"></th>
                            <th class="group-start">{{ __('Touches') }}</th>
                            <th>{{ __('Share of points') }}</th>
                            <th>+/−</th>
                            @foreach ($usedSkills as $s)<th @class(['group-start' => $loop->first]) title="{{ __('Value from') }} {{ $s->label() }}">{{ $s->code() }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ranking as $i => $p)
                            @php $v = $p->value(); @endphp
                            <tr>
                                <td class="!text-left text-muted">{{ $i + 1 }}</td>
                                <td class="!text-left"><b>{{ $p->player?->numberLabel() }}</b> {{ $p->player && ! $p->player->isAnonymous() ? $p->player->short_name : '' }}</td>
                                <td @class(['group-start font-display text-base font-bold', 'text-success-400' => $v > 0, 'text-danger-400' => $v < 0])>{{ $num($v) }}</td>
                                <td class="font-semibold">{{ $num($p->valuePerSet()) }}</td>
                                <td class="text-muted">{{ $num($p->valuePerTouch(), 2) }}</td>
                                <td class="!text-left">
                                    <span class="relative block h-2 rounded-full bg-surface-3">
                                        <span class="absolute inset-y-0 left-1/2 w-px bg-line-strong"></span>
                                        @if ($v >= 0)
                                            <span class="absolute inset-y-0 left-1/2 rounded-r-full bg-success-500" style="width: {{ round(50 * $v / $maxValue) }}%"></span>
                                        @else
                                            <span class="absolute inset-y-0 right-1/2 rounded-l-full bg-danger-500" style="width: {{ round(50 * abs($v) / $maxValue) }}%"></span>
                                        @endif
                                    </span>
                                </td>
                                <td class="group-start">{{ $p->touches() }}</td>
                                <td>{{ round(100 * $p->points() / $teamPoints) }}%</td>
                                <td @class(['text-success-400' => $p->plusMinus() > 0, 'text-danger-400' => $p->plusMinus() < 0])>{{ $p->plusMinus() }}</td>
                                @foreach ($usedSkills as $s)
                                    @php $sv = PlayerValue::of($p->skill($s)); @endphp
                                    <td @class(['group-start' => $loop->first, 'text-subtle' => $p->skill($s)->total() === 0, 'text-success-400' => $sv > 0, 'text-danger-400' => $sv < 0])>{{ $p->skill($s)->total() ? $num($sv) : '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="20" class="py-8 text-muted">{{ __('No actions assigned to players yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <details class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-sm">
                    <summary class="cursor-pointer font-semibold">{{ __('How the value is calculated') }}</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="stats-table">
                            <thead><tr><th class="!text-left">{{ __('Skill') }}</th>@foreach ($grades as $g)<th>{{ $g }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach (MatchReport::SKILLS as $s)
                                    <tr>
                                        <td class="!text-left">{{ $s->label() }}</td>
                                        @foreach ($grades as $g)
                                            @php $w = PlayerValue::weight($s, $g); @endphp
                                            <td @class(['text-success-400' => $w > 0, 'text-danger-400' => $w < 0, 'text-subtle' => $w == 0])>{{ $w > 0 ? '+' : '' }}{{ $num($w) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-muted">{{ __('Per set = value / sets played, so starters and substitutes can be compared. Per touch shows how clean the touches are, independent of volume.') }}</p>
                </details>
            </div>
        </x-ui.card>
    </section>
    </div>
</div>
