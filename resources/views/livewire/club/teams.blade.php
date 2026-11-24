<div>
    <x-ui.page-header eyebrow="{{ __('Club panel') }}" title="{{ __('Teams') }}" subtitle="{{ __('Seniors, juniors, cadets… Every match belongs to one team.') }}" />

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="$editingId ? __('Edit team') : __('New team')">
            <form wire:submit="save" class="space-y-4">
                <x-ui.field label="{{ __('Name') }}" for="t-name" error="name"><input id="t-name" wire:model="name" class="input" placeholder="{{ __('e.g. Juniors') }}" required></x-ui.field>
                <x-ui.field label="{{ __('Category') }}" for="t-cat" error="category"><input id="t-cat" wire:model="category" class="input" placeholder="{{ __('e.g. U19, 2nd league') }}"></x-ui.field>
                <x-ui.field label="{{ __('Season') }}" for="t-season" error="season"><input id="t-season" wire:model="season" class="input"></x-ui.field>
                <div class="flex justify-end gap-2">
                    @if ($editingId)<x-ui.button variant="ghost" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>@endif
                    <x-ui.button type="submit" icon="check">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="grid content-start gap-3 sm:grid-cols-2 lg:col-span-2">
            @foreach ($teams as $team)
                <div wire:key="t-{{ $team->id }}" class="card p-5 animate-fade-up">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-xl font-semibold">{{ $team->name }}</p>
                            <p class="text-sm text-muted">{{ collect([$team->category, $team->season])->filter()->join(' · ') ?: '—' }}</p>
                        </div>
                        <div class="flex gap-1">
                            <button wire:click="edit({{ $team->id }})" class="rounded-lg p-1.5 text-subtle hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="size-4" /></button>
                            <button wire:click="delete({{ $team->id }})" wire:confirm="{{ __('Delete team? Its players stay in the club without a team.') }}" class="rounded-lg p-1.5 text-subtle hover:bg-surface-2 hover:text-danger-400" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="brand">{{ __(':count players', ['count' => $team->players_count]) }}</x-ui.badge>
                        <x-ui.badge tone="energy">{{ __(':count matches', ['count' => $team->games_count]) }}</x-ui.badge>
                        <a href="{{ route('players.index', ['team' => $team->id]) }}" wire:navigate class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-brand-400 hover:text-brand-300"><x-icon name="users" class="size-4" /> {{ __('Roster') }}</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
