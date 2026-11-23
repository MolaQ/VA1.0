<div>
    <a href="{{ route('players.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-fg"><x-icon name="arrow-left" class="size-4" /> {{ __('Players') }}</a>

    <div class="card relative mb-6 overflow-hidden p-6 sm:p-8 animate-fade-up">
        <div class="absolute -right-20 -top-24 size-72 rounded-full bg-gradient-to-br from-brand-500/25 to-accent-400/10 blur-3xl"></div>
        <div class="relative flex flex-wrap items-center gap-6">
            <span class="grid size-24 place-items-center rounded-3xl bg-gradient-to-br from-brand-500 to-accent-500 font-display text-5xl font-bold text-white shadow-2xl shadow-brand-500/30">{{ $player->number ?? '–' }}</span>
            <div>
                <h1 class="font-display text-4xl font-bold">{{ $player->full_name }}</h1>
                <p class="mt-1 text-muted">{{ $player->position?->label() ?? __('Position not set') }} · {{ $player->team?->name ?? __('No team') }}</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-ui.stat label="{{ __('Points') }}" :value="$season->points" :hint="'BP '.$season->breakPoints.' · '.__('balance').' '.$season->balance()" icon="bolt" tone="energy" />
        <x-ui.stat label="{{ __('Attack efficiency') }}" :value="\App\Stats\PlayerStatLine::percent($season->killPercentage())" :hint="$season->attackKills.' / '.$season->attackTotal.' · eff '.\App\Stats\PlayerStatLine::percent($season->attackEfficiency())" icon="chart" />
        <x-ui.stat label="{{ __('Positive reception') }}" :value="\App\Stats\PlayerStatLine::percent($season->receptionPositive())" :hint="__(':count receptions', ['count' => $season->receptionTotal]).' · perf '.\App\Stats\PlayerStatLine::percent($season->receptionPerfect())" icon="shield" tone="success" />
        <x-ui.stat label="{{ __('Serve') }}" :value="$season->serveAces" :hint="__('aces').' · '.__(':count serves', ['count' => $season->serveTotal]).' · eff '.\App\Stats\PlayerStatLine::percent($season->serveEfficiency())" icon="volleyball" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <x-ui.card title="{{ __('Points per match') }}" subtitle="{{ __('Last 20 matches') }}" class="xl:col-span-1">
            @if ($perGame->isEmpty())
                <p class="text-sm text-muted">{{ __('No data.') }}</p>
            @else
                <div class="flex h-48 items-end gap-1.5">
                    @foreach ($perGame->reverse() as $row)
                        <a href="{{ route('games.show', $row['game']) }}" wire:navigate class="group flex flex-1 flex-col items-center justify-end gap-1" title="{{ $row['game']->opponent }}: {{ $row['line']->points }} {{ __('pts') }}">
                            <span class="text-[10px] font-semibold tabular text-muted opacity-0 transition group-hover:opacity-100">{{ $row['line']->points }}</span>
                            <span class="w-full rounded-t-md bg-gradient-to-t from-brand-600 to-accent-400 transition-all duration-700 group-hover:from-volley-600 group-hover:to-volley-400" style="height: {{ max(4, $row['line']->points / $maxPoints * 100) }}%"></span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="{{ __('Match by match') }}" class="xl:col-span-2" padding="p-0">
            <div class="overflow-x-auto">
                <table class="stats-table">
                    <thead><tr>
                        <th class="!text-left">{{ __('Match') }}</th><th>{{ __('Pts') }}</th><th>{{ __('Attack') }}</th><th>Kill%</th><th>{{ __('Rec.') }}</th><th>{{ __('Pos%') }}</th><th>{{ __('Srv.') }}</th><th>Eff%</th><th>{{ __('Block') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($perGame as $row)
                            @php $l = $row['line']; @endphp
                            <tr>
                                <td class="!text-left"><a href="{{ route('games.show', $row['game']) }}" wire:navigate class="font-semibold hover:text-brand-400">vs {{ $row['game']->opponent }}</a><span class="block text-xs text-muted">{{ $row['game']->played_at->format('d.m.Y') }} · {{ $row['game']->result() }}</span></td>
                                <td class="font-display text-base font-bold text-volley-400">{{ $l->points }}</td>
                                <td>{{ $l->attackKills }}/{{ $l->attackTotal }}</td>
                                <td><x-pct :value="$l->killPercentage()" /></td>
                                <td>{{ $l->receptionTotal }}</td>
                                <td><x-pct :value="$l->receptionPositive()" :good="0.55" /></td>
                                <td>{{ $l->serveTotal }}</td>
                                <td><x-pct :value="$l->serveEfficiency()" :good="0.15" /></td>
                                <td>{{ $l->blockPoints }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="!py-10 text-muted">{{ __('This player has no match stats yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
