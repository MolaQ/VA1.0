@php
    $sets = $stats->setNumbers ?: [1, 2, 3];
    $setCols = max(5, count($sets));
@endphp
{{-- Desktop / tablet / TV: full box score --}}
<div class="hidden overflow-x-auto md:block">
    <table class="stats-table">
        <thead>
            <tr>
                <th rowspan="2" class="!text-left">{{ __('No.') }}</th>
                <th rowspan="2" class="sticky left-0 z-20 !text-left">{{ __('Player') }}</th>
                <th colspan="{{ $setCols }}" class="group-start">{{ __('Sets') }}</th>
                <th colspan="3" class="group-start">{{ __('Points') }}</th>
                <th colspan="4" class="group-start">{{ __('Serve') }}</th>
                <th colspan="4" class="group-start">{{ __('Reception') }}</th>
                <th colspan="6" class="group-start">{{ __('Attack') }}</th>
                <th colspan="2" class="group-start">{{ __('Block') }}</th>
            </tr>
            <tr>
                @for ($i = 1; $i <= $setCols; $i++)<th @class(['group-start' => $i === 1])>S{{ $i }}</th>@endfor
                <th class="group-start" title="{{ __('Total points') }}">{{ __('Total') }}</th><th title="{{ __('Points on own serve (break points)') }}">BP</th><th title="{{ __('Balance: points − errors') }}">{{ __('Bal') }}</th>
                <th class="group-start">{{ __('Total') }}</th><th>{{ __('Err') }}</th><th>{{ __('Ace') }}</th><th>Eff%</th>
                <th class="group-start">{{ __('Total') }}</th><th>{{ __('Err') }}</th><th title="(# + +) / {{ __('Total') }}">{{ __('Pos%') }}</th><th title="# / {{ __('Total') }}">Perf%</th>
                <th class="group-start">{{ __('Total') }}</th><th>{{ __('Err') }}</th><th title="{{ __('Blocked') }}">{{ __('Block') }}</th><th>{{ __('Pts') }}</th><th>Kill%</th><th>Eff%</th>
                <th class="group-start">{{ __('Pts') }}</th><th title="{{ __('Block touches') }}">{{ __('Touch') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stats->lines as $line)
                @php $p = $line->player; $pid = $p?->id; @endphp
                <tr wire:key="row-{{ $pid }}" @class(['opacity-60' => ! $line->hasPlayed()])>
                    <td class="!text-left font-display text-base font-bold text-muted">{{ $p?->number }}</td>
                    <td class="sticky left-0 z-[5] bg-surface !text-left">
                        <a href="{{ route('players.show', $p) }}" wire:navigate class="font-semibold hover:text-brand-400">{{ $p?->full_name }}</a>
                        @if ($p?->isLibero())<span class="ml-1 rounded bg-warning-500/90 px-1 text-[10px] font-bold text-black">L</span>@endif
                    </td>
                    @for ($i = 1; $i <= $setCols; $i++)
                        <td @class(['group-start' => $i === 1, 'text-muted'])>{{ $line->sets[$i] ?? '' }}</td>
                    @endfor
                    <td class="group-start font-semibold"><x-stat-cell :value="$line->points" :player="$pid" skill="points" /></td>
                    <td>{{ $line->breakPoints }}</td>
                    <td @class(['text-success-400' => $line->balance() > 0, 'text-danger-400' => $line->balance() < 0])>{{ $line->balance() }}</td>

                    <td class="group-start"><x-stat-cell :value="$line->serveTotal" :player="$pid" skill="serve" /></td>
                    <td><x-stat-cell :value="$line->serveErrors" :player="$pid" skill="serve" filter="=" /></td>
                    <td><x-stat-cell :value="$line->serveAces" :player="$pid" skill="serve" filter="#" /></td>
                    <td><x-pct :value="$line->serveEfficiency()" :good="0.15" /></td>

                    <td class="group-start"><x-stat-cell :value="$line->receptionTotal" :player="$pid" skill="reception" /></td>
                    <td><x-stat-cell :value="$line->receptionErrors" :player="$pid" skill="reception" filter="=" /></td>
                    <td><x-pct :value="$line->receptionPositive()" :good="0.55" /></td>
                    <td><x-pct :value="$line->receptionPerfect()" :good="0.35" /></td>

                    <td class="group-start"><x-stat-cell :value="$line->attackTotal" :player="$pid" skill="attack" /></td>
                    <td><x-stat-cell :value="$line->attackErrors" :player="$pid" skill="attack" filter="=" /></td>
                    <td><x-stat-cell :value="$line->attackBlocked" :player="$pid" skill="attack" filter="/" /></td>
                    <td><x-stat-cell :value="$line->attackKills" :player="$pid" skill="attack" filter="#" /></td>
                    <td><x-pct :value="$line->killPercentage()" :good="0.5" /></td>
                    <td><x-pct :value="$line->attackEfficiency()" :good="0.35" /></td>

                    <td class="group-start"><x-stat-cell :value="$line->blockPoints" :player="$pid" skill="block" filter="#" /></td>
                    <td><x-stat-cell :value="$line->blockTouches" :player="$pid" skill="block" filter="touch" /></td>
                </tr>
            @empty
                <tr><td colspan="40" class="!py-10 text-muted">{{ __('No players in this team\'s roster.') }}</td></tr>
            @endforelse
        </tbody>
        @if ($showTotals)
            @php $t = $stats->totals; @endphp
            <tfoot>
                <tr class="font-semibold">
                    <td class="!text-left font-display text-muted">T</td>
                    <td class="sticky left-0 bg-surface !text-left">{{ __('Totals') }}</td>
                    <td colspan="{{ $setCols }}" class="group-start"></td>
                    <td class="group-start font-display text-base text-volley-400">{{ $t->points }}</td><td>{{ $t->breakPoints }}</td><td>{{ $t->balance() }}</td>
                    <td class="group-start">{{ $t->serveTotal }}</td><td>{{ $t->serveErrors }}</td><td>{{ $t->serveAces }}</td><td><x-pct :value="$t->serveEfficiency()" :good="0.15" /></td>
                    <td class="group-start">{{ $t->receptionTotal }}</td><td>{{ $t->receptionErrors }}</td><td><x-pct :value="$t->receptionPositive()" :good="0.55" /></td><td><x-pct :value="$t->receptionPerfect()" :good="0.35" /></td>
                    <td class="group-start">{{ $t->attackTotal }}</td><td>{{ $t->attackErrors }}</td><td>{{ $t->attackBlocked }}</td><td>{{ $t->attackKills }}</td><td><x-pct :value="$t->killPercentage()" /></td><td><x-pct :value="$t->attackEfficiency()" :good="0.35" /></td>
                    <td class="group-start">{{ $t->blockPoints }}</td><td>{{ $t->blockTouches }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

{{-- Phone: player cards --}}
<div class="grid gap-3 md:hidden">
    @foreach ($stats->lines->filter->hasPlayed() as $line)
        @php $p = $line->player; $pid = $p?->id; @endphp
        <div class="rounded-2xl border border-line bg-surface-2 p-4" wire:key="card-{{ $pid }}">
            <div class="flex items-center gap-3">
                <span class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-accent-500 font-display text-xl font-bold text-white">{{ $p?->number }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ $p?->full_name }} @if ($p?->isLibero())<span class="rounded bg-warning-500/90 px-1 text-[10px] font-bold text-black">L</span>@endif</p>
                    <p class="text-xs text-muted">BP {{ $line->breakPoints }} · {{ __('Balance') }} {{ $line->balance() }}</p>
                </div>
                <div class="text-right">
                    <p class="font-display text-3xl font-bold leading-none text-volley-400"><x-stat-cell :value="$line->points" :player="$pid" skill="points" /></p>
                    <p class="text-[10px] font-semibold tracking-wide text-muted uppercase">{{ __('pts') }}</p>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-4 gap-2 text-center text-xs">
                <div class="rounded-xl bg-surface-3 p-2"><p class="text-muted">{{ __('Serve') }}</p><p class="mt-1 font-semibold tabular"><x-stat-cell :value="$line->serveTotal" :player="$pid" skill="serve" /> · <x-pct :value="$line->serveEfficiency()" :good="0.15" /></p></div>
                <div class="rounded-xl bg-surface-3 p-2"><p class="text-muted">{{ __('Reception') }}</p><p class="mt-1 font-semibold tabular"><x-stat-cell :value="$line->receptionTotal" :player="$pid" skill="reception" /> · <x-pct :value="$line->receptionPositive()" :good="0.55" /></p></div>
                <div class="rounded-xl bg-surface-3 p-2"><p class="text-muted">{{ __('Attack') }}</p><p class="mt-1 font-semibold tabular"><x-stat-cell :value="$line->attackTotal" :player="$pid" skill="attack" /> · <x-pct :value="$line->killPercentage()" /></p></div>
                <div class="rounded-xl bg-surface-3 p-2"><p class="text-muted">{{ __('Block') }}</p><p class="mt-1 font-semibold tabular"><x-stat-cell :value="$line->blockPoints" :player="$pid" skill="block" filter="#" /></p></div>
            </div>
        </div>
    @endforeach
    @if ($stats->lines->filter->hasPlayed()->isEmpty())
        <p class="py-6 text-center text-sm text-muted">{{ __('Stats will appear after the match analysis.') }}</p>
    @endif
</div>
