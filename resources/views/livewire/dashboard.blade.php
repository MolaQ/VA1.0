<div>
    <x-ui.page-header :eyebrow="$club->name" title="{{ __('Dashboard') }}" subtitle="{{ __('Season overview and the latest match analyses.') }}">
        @if ($isStaff)
            <x-slot:actions>
                <x-ui.button :href="route('games.create')" icon="plus">{{ __('Add match') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-ui.stat label="{{ __('Matches (12 mo.)') }}" :value="$kpi['games']" icon="video" />
        <x-ui.stat label="{{ __('Wins') }}" :value="$kpi['wins']" icon="bolt" tone="success" />
        <x-ui.stat label="{{ __('Active players') }}" :value="$kpi['players']" icon="users" tone="energy" />
        <x-ui.stat label="{{ __('Recorded actions') }}" :value="$kpi['actions']" icon="chart" />
    </div>

    @if ($inProgress->isNotEmpty())
        <x-ui.card class="mt-6" title="{{ __('AI analysis in progress') }}" subtitle="{{ __('Progress updates live.') }}">
            <div class="space-y-4">
                @foreach ($inProgress as $game)
                    <a href="{{ route('games.show', $game) }}" wire:navigate class="block">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-semibold">{{ $game->team->name }} vs {{ $game->opponent }}</span>
                            <span class="tabular text-muted">{{ $game->analysis_progress }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-3">
                            <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-accent-400 transition-all duration-700" style="width: {{ $game->analysis_progress }}%"></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @if ($children->isNotEmpty())
        <h2 class="mb-3 mt-8 font-display text-xl font-semibold">{{ __('My children') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($children as $child)
                <a href="{{ route('players.show', $child) }}" wire:navigate class="card flex items-center gap-4 p-4 transition hover:-translate-y-0.5 hover:border-line-strong">
                    <span class="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-volley-500 to-volley-600 font-display text-2xl font-bold text-white">{{ $child->number ?? $child->initials() }}</span>
                    <span>
                        <span class="block font-semibold">{{ $child->full_name }}</span>
                        <span class="block text-sm text-muted">{{ $child->team?->name }} · {{ $child->position?->label() }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="mt-8 grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-display text-xl font-semibold">{{ __('Latest matches') }}</h2>
                <a href="{{ route('games.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-brand-400 hover:text-brand-300">{{ __('All') }} <x-icon name="arrow-right" class="size-4" /></a>
            </div>
            @if ($recent->isEmpty())
                <x-ui.empty icon="video" title="{{ __('No matches') }}" text="{{ __('Add your first match with a link to the YouTube recording. We\'ll generate the stats automatically.') }}">
                    @if ($isStaff)<x-ui.button :href="route('games.create')" icon="plus" class="mt-2">{{ __('Add match') }}</x-ui.button>@endif
                </x-ui.empty>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($recent as $game)
                        <x-game-card :game="$game" wire:key="g-{{ $game->id }}" style="animation-delay: {{ $loop->index * 60 }}ms" />
                    @endforeach
                </div>
            @endif
        </div>

        <x-ui.card title="{{ __('Top scorers') }}" :subtitle="$lastGame ? 'vs '.$lastGame->opponent.' · '.$lastGame->played_at->format('d.m.Y') : __('Last analysed match')">
            @forelse ($leaders as $line)
                <div class="flex items-center gap-3 py-2.5 {{ $loop->first ? '' : 'border-t border-line' }}">
                    <span class="w-6 text-center font-display text-lg font-bold {{ $loop->first ? 'text-volley-400' : 'text-subtle' }}">{{ $loop->iteration }}</span>
                    <span class="grid size-9 place-items-center rounded-xl bg-surface-3 font-display font-bold">{{ $line->player?->number }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{ $line->player?->full_name }}</span>
                        <span class="block text-xs text-muted">{{ __('Attack') }} {{ \App\Stats\PlayerStatLine::percent($line->killPercentage()) }} · {{ __('Aces') }} {{ $line->serveAces }} · {{ __('Block') }} {{ $line->blockPoints }}</span>
                    </span>
                    <span class="font-display text-2xl font-bold tabular">{{ $line->points }}</span>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('Top scorers will appear after the first match analysis.') }}</p>
            @endforelse
        </x-ui.card>
    </div>
</div>
