@use('App\Enums\Skill')
@use('App\Stats\PlayerStatLine')
@php
    $pct = fn (?float $v) => PlayerStatLine::percent($v);
    $num = fn (?float $v, int $d = 1) => $v === null ? '–' : number_format($v, $d, ',', ' ');
    $games = $comparison->games;
    $fmt = fn (?float $v, array $def) => $def['pct'] ? $pct($v) : $num($v);
@endphp
<div class="space-y-6">
    <x-ui.page-header eyebrow="{{ __('Reports') }}" title="{{ __('Match comparison') }}" subtitle="{{ __('Pick up to 10 matches: the same numbers side by side, the total and the trend of the latest match.') }}">
        <x-slot:actions>
            <x-ui.button class="no-print" size="sm" variant="secondary" icon="download" x-on:click="window.print()">{{ __('PDF / Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Picker --}}
    <x-ui.card class="no-print" padding="p-4">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <select wire:model.live="team" class="input !w-auto">
                <option value="">{{ __('All teams') }}</option>
                @foreach ($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
            <span class="text-sm text-muted">{{ count($selected) }} / {{ \App\Livewire\Games\GameCompare::MAX }} {{ __('selected') }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
            @forelse ($candidates as $g)
                @php $on = in_array($g->id, array_map('intval', $selected), true); $won = $g->isWon(); @endphp
                <button type="button" wire:click="toggle({{ $g->id }})" wire:key="pick-{{ $g->id }}"
                        @class(['rounded-xl border px-3 py-1.5 text-left text-xs transition', 'border-brand-500 bg-brand-500/15 text-fg' => $on, 'border-line bg-surface-2 text-muted hover:text-fg' => ! $on])>
                    <span class="font-semibold">{{ $g->opponent }}</span>
                    <span class="ml-1 tabular">{{ $g->played_at->format('d.m') }}</span>
                    <span @class(['ml-1 font-bold tabular', 'text-success-400' => $won === true, 'text-danger-400' => $won === false])>{{ $g->result() }}</span>
                </button>
            @empty
                <p class="text-sm text-muted">{{ __('No matches yet') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    @if ($games->isEmpty())
        <x-ui.empty icon="chart" title="{{ __('Pick matches to compare') }}" text="{{ __('Choose at least one match above.') }}" />
    @else
        <div wire:loading.class="opacity-60" class="space-y-6">
        {{-- Team, match by match --}}
        <x-ui.card :title="__('Team match by match')" :subtitle="__('Total = computed from all actions of the chosen matches. Trend = latest match against the average of the others.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th class="!text-left">{{ __('Metric') }}</th>
                            @foreach ($games as $g)
                                <th class="group-start normal-case">
                                    <a href="{{ route('games.report', $g) }}" wire:navigate class="hover:text-brand-400">
                                        <span class="block font-semibold text-fg">{{ $g->opponent }}</span>
                                        <span class="block font-normal">{{ $g->played_at->format('d.m.Y') }} · {{ $g->result() }}</span>
                                    </a>
                                </th>
                            @endforeach
                            <th class="group-start">{{ __('Total') }}</th>
                            <th title="{{ __('Latest match against the average of the others') }}">{{ __('Trend') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $total = $comparison->total->kpis(); @endphp
                        @foreach ($kpis as $key => $def)
                            @php
                                $trend = $comparison->trend($key);
                                $better = $trend === null || abs($trend) < 0.0001 ? null : (($trend > 0) === $def['higher']);
                            @endphp
                            <tr>
                                <td class="!text-left font-semibold">{{ $def['label'] }}</td>
                                @foreach ($games as $g)
                                    @php $v = $comparison->matches[$g->id]->kpis()[$key]; @endphp
                                    <td class="group-start">
                                        @if ($def['pct'] && $def['good'] !== null)
                                            <x-pct :value="$v" :good="$def['good']" />
                                        @else
                                            {{ $fmt($v, $def) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="group-start font-semibold">{{ $fmt($total[$key], $def) }}</td>
                                <td @class(['font-semibold', 'text-success-400' => $better === true, 'text-danger-400' => $better === false, 'text-subtle' => $better === null])>
                                    @if ($trend === null) – @else {{ $trend > 0 ? '▲' : ($trend < 0 ? '▼' : '') }} {{ $def['pct'] ? round(abs($trend) * 100).' pp' : $num(abs($trend)) }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Players, match by match --}}
        <x-ui.card :title="__('Players match by match')" :subtitle="__('Usefulness per set in each match, and the totals over the chosen matches.')" padding="p-0 sm:p-0">
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th rowspan="2" class="!text-left">{{ __('Player') }}</th>
                            <th colspan="{{ $games->count() }}" class="group-start">{{ __('Value per set') }}</th>
                            <th colspan="6" class="group-start">{{ __('Total') }}</th>
                        </tr>
                        <tr>
                            @foreach ($games as $g)<th @class(['group-start' => $loop->first]) title="{{ $g->opponent }}">{{ $g->played_at->format('d.m') }}</th>@endforeach
                            <th class="group-start">{{ __('Sets') }}</th>
                            <th>{{ __('Value') }}</th>
                            <th>{{ __('Per set') }}</th>
                            <th>{{ __('Pts') }}</th>
                            <th title="{{ __('Attack success') }}">{{ __('Attack') }} #</th>
                            <th title="{{ __('Positive reception') }}">{{ __('Rec.') }} #+</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($comparison->players as $row)
                            @php $t = $row['total']; @endphp
                            <tr>
                                <td class="!text-left"><x-jersey :player="$row['player']" /></td>
                                @foreach ($games as $g)
                                    @php $line = $row['games'][$g->id] ?? null; $v = $line?->valuePerSet(); @endphp
                                    <td @class(['group-start' => $loop->first, 'text-success-400' => $v !== null && $v > 0, 'text-danger-400' => $v !== null && $v < 0, 'text-subtle' => $line === null])>{{ $line ? $num($v) : '·' }}</td>
                                @endforeach
                                <td class="group-start text-muted">{{ $t->setsPlayed() }}</td>
                                <td @class(['font-semibold', 'text-success-400' => $t->value() > 0, 'text-danger-400' => $t->value() < 0])>{{ $num($t->value()) }}</td>
                                <td class="font-display text-base font-bold">{{ $num($t->valuePerSet()) }}</td>
                                <td>{{ $t->points() }}</td>
                                <td>@if ($t->skill(Skill::Attack)->total())<x-pct :value="$t->skill(Skill::Attack)->success()" :good="0.5" /> <span class="text-[10px] text-muted">{{ $t->skill(Skill::Attack)->total() }}</span>@endif</td>
                                <td>@if ($t->skill(Skill::Reception)->total())<x-pct :value="$t->skill(Skill::Reception)->success()" :good="0.55" /> <span class="text-[10px] text-muted">{{ $t->skill(Skill::Reception)->total() }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="20" class="py-8 text-muted">{{ __('No actions assigned to players yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
        </div>
    @endif
</div>
