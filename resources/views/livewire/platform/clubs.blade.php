<div>
    <x-ui.page-header eyebrow="{{ __('Platform') }}" title="{{ __('Clubs') }}" subtitle="{{ __('All clubs using VVA.') }}" />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($clubs as $club)
            <div wire:key="c-{{ $club->id }}" class="card p-5 animate-fade-up">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 place-items-center rounded-xl bg-surface-3 font-bold" style="color: {{ $club->accent_color ?? '#60a5fa' }}">{{ $club->initials() }}</span>
                    <div class="min-w-0 flex-1"><p class="truncate font-semibold">{{ $club->name }}</p><p class="text-sm text-muted">{{ $club->city ?? '—' }}</p></div>
                    <x-ui.button size="sm" variant="secondary" wire:click="enter({{ $club->id }})">{{ __('Enter') }}</x-ui.button>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-ui.badge>{{ __(':count accounts', ['count' => $club->memberships_count]) }}</x-ui.badge>
                    <x-ui.badge tone="brand">{{ __(':count players', ['count' => $club->players_count]) }}</x-ui.badge>
                    <x-ui.badge tone="energy">{{ __(':count matches', ['count' => $club->games_count]) }}</x-ui.badge>
                </div>
            </div>
        @endforeach
    </div>
</div>
