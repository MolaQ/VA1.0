<div>
    <x-ui.page-header eyebrow="{{ __('Roster') }}" title="{{ __('Players') }}" subtitle="{{ __('Player profiles and their stats from analysed matches.') }}">
        <x-slot:actions>
            <input type="search" wire:model.live.debounce.300ms="search" class="input sm:w-64" placeholder="{{ __('Search (name, number)…') }}">
            @if ($isStaff)<x-ui.button icon="plus" wire:click="create">{{ __('Add player') }}</x-ui.button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($teams->count() > 1)
        <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="{{ __('Team') }}">
            <button type="button" wire:click="$set('teamFilter', null)" @class(['rounded-full border px-3 py-1 text-sm transition', 'border-brand-500 bg-brand-500/15 text-fg' => ! $teamFilter, 'border-line text-muted hover:text-fg' => $teamFilter])>{{ __('All teams') }}</button>
            @foreach ($teams as $t)
                <button type="button" wire:key="tf-{{ $t->id }}" wire:click="$set('teamFilter', {{ $t->id }})" @class(['rounded-full border px-3 py-1 text-sm transition', 'border-brand-500 bg-brand-500/15 text-fg' => $teamFilter === $t->id, 'border-line text-muted hover:text-fg' => $teamFilter !== $t->id])>{{ $t->name }}</button>
            @endforeach
        </div>
    @endif

    @forelse ($groups as $team => $players)
        <h2 class="mb-3 mt-6 flex items-center gap-2 font-display text-xl font-semibold first:mt-0">{{ $team }} <x-ui.badge>{{ $players->count() }}</x-ui.badge></h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($players as $player)
                <div wire:key="p-{{ $player->id }}" class="card group flex items-center gap-4 p-4 transition hover:-translate-y-0.5 hover:border-line-strong animate-fade-up" style="animation-delay: {{ $loop->index * 30 }}ms">
                    <a href="{{ route('players.show', $player) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-4">
                        <span @class([
                            'grid size-14 shrink-0 place-items-center rounded-2xl font-display text-2xl font-bold text-white shadow-lg',
                            'bg-gradient-to-br from-warning-400 to-volley-500 shadow-volley-500/20' => $player->isLibero(),
                            'bg-gradient-to-br from-brand-500 to-accent-500 shadow-brand-500/20' => ! $player->isLibero(),
                        ])>{{ $player->number ?? '–' }}</span>
                        <span class="min-w-0">
                            <span class="block truncate font-semibold">{{ $player->full_name }}</span>
                            <span class="block text-sm text-muted">{{ $player->position?->label() ?? __('Position not set') }}</span>
                            @unless ($player->is_active)<x-ui.badge class="mt-1">{{ __('Inactive') }}</x-ui.badge>@endunless
                        </span>
                    </a>
                    @if ($isStaff)
                        <button wire:click="edit({{ $player->id }})" class="rounded-lg p-2 text-subtle opacity-0 transition hover:bg-surface-2 hover:text-fg group-hover:opacity-100 max-lg:opacity-100" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="size-4" /></button>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <x-ui.empty icon="users" title="{{ __('No players') }}" text="{{ __('Add the roster with jersey numbers: the AI recognises players by their numbers.') }}">
            @if ($isStaff)<x-ui.button icon="plus" wire:click="create" class="mt-2">{{ __('Add player') }}</x-ui.button>@endif
        </x-ui.empty>
    @endforelse

    @if ($isStaff)
        <x-ui.modal name="player-form" :title="$editingId ? __('Edit player') : __('New player')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <p class="text-sm text-muted sm:col-span-2">{{ __('A jersey number is enough. Without a name the player is shown as #47; add a name or a nickname whenever you like.') }}</p>
                <x-ui.field label="{{ __('First name') }}" for="first_name" error="first_name"><input id="first_name" wire:model="first_name" class="input"></x-ui.field>
                <x-ui.field label="{{ __('Last name') }}" for="last_name" error="last_name"><input id="last_name" wire:model="last_name" class="input"></x-ui.field>
                <x-ui.field label="{{ __('Nickname') }}" for="nickname" error="nickname"><input id="nickname" wire:model="nickname" class="input" placeholder="{{ __('optional') }}"></x-ui.field>
                <x-ui.field label="{{ __('Number') }}" for="number" error="number"><input id="number" type="number" min="0" max="99" wire:model="number" class="input"></x-ui.field>
                <x-ui.field label="{{ __('Position') }}" for="position" error="position">
                    <select id="position" wire:model="position" class="input">
                        <option value="">—</option>
                        @foreach ($positions as $pos)<option value="{{ $pos->value }}">{{ $pos->label() }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="{{ __('Team') }}" for="team_id" error="team_id">
                    <select id="team_id" wire:model="team_id" class="input">
                        <option value="">—</option>
                        @foreach ($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="{{ __('Date of birth') }}" for="birth_date" error="birth_date"><input id="birth_date" type="date" wire:model="birth_date" class="input"></x-ui.field>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="is_active" class="size-4 accent-brand-500"> {{ __('Active in the roster') }}</label>
                <div class="flex items-center justify-end gap-2 sm:col-span-2">
                    @if ($canDelete)
                        <x-ui.button variant="ghost" icon="trash" class="mr-auto text-danger-400" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete player? If they already have actions in matches, they will only be marked inactive.') }}">{{ __('Delete') }}</x-ui.button>
                    @endif
                    <x-ui.button variant="ghost" x-on:click="$dispatch('close-modal', 'player-form')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" icon="check">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
