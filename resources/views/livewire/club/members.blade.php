@php
    $roleTones = ['admin' => 'energy', 'trainer' => 'brand', 'player' => 'accent', 'parent' => 'success'];
@endphp
<div>
    <x-ui.page-header eyebrow="{{ __('Club panel') }}" title="{{ __('Members & roles') }}" subtitle="{{ __('Administrator, coach, player, parent. A parent only sees their own child\'s data.') }}">
        <x-slot:actions>
            <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'invite')">{{ __('Add person') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($roles as $r)
            <div class="card p-4">
                <x-ui.badge :tone="$roleTones[$r->value]">{{ $r->label() }}</x-ui.badge>
                <p class="mt-2 font-display text-2xl font-bold tabular">{{ $members->filter(fn ($m) => $m['memberships']->contains('role', $r))->count() }}</p>
            </div>
        @endforeach
    </div>

    <x-ui.card padding="p-0">
        <ul class="divide-y divide-line">
            @foreach ($members as $m)
                <li wire:key="u-{{ $m['user']->id }}" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <span class="grid size-10 place-items-center rounded-xl bg-surface-3 text-sm font-bold">{{ $m['user']->initials() }}</span>
                    <div class="min-w-48 flex-1">
                        <p class="font-semibold">{{ $m['user']->name }}</p>
                        <p class="text-sm text-muted">{{ $m['user']->email }}</p>
                        @if ($m['children']->isNotEmpty())
                            <p class="mt-1 flex flex-wrap gap-1 text-xs text-muted">{{ __('Children') }}:
                                @foreach ($m['children'] as $child)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-surface-3 px-1.5 py-0.5">#{{ $child->number }} {{ $child->full_name }}
                                        <button wire:click="unlinkChild({{ $m['user']->id }}, {{ $child->id }})" class="hover:text-danger-400" aria-label="{{ __('Unlink') }}"><x-icon name="x" class="size-3" /></button>
                                    </span>
                                @endforeach
                            </p>
                        @endif
                        @if ($m['profile'])<p class="mt-1 text-xs text-muted">{{ __('Player profile') }}: #{{ $m['profile']->number }} {{ $m['profile']->full_name }}</p>@endif
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($m['memberships'] as $membership)
                            <x-ui.badge :tone="$roleTones[$membership->role->value]" class="group">
                                {{ $membership->role->label() }}
                                <button wire:click="removeRole({{ $membership->id }})" wire:confirm="{{ __('Remove the :role role?', ['role' => $membership->role->label()]) }}" class="opacity-50 hover:opacity-100" aria-label="{{ __('Remove role') }}"><x-icon name="x" class="size-3" /></button>
                            </x-ui.badge>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.modal name="invite" title="{{ __('Add a person to the club') }}">
        @if ($temporaryPassword)
            <div class="mb-5 rounded-xl border border-success-500/30 bg-success-500/10 p-4 text-sm">
                <p class="font-semibold text-success-400">{{ __('Account created') }}</p>
                <p class="mt-1 text-muted">{{ __('Temporary password (shown only once):') }}</p>
                <p class="mt-2 select-all rounded-lg bg-surface-3 px-3 py-2 font-mono text-base">{{ $temporaryPassword }}</p>
            </div>
        @endif
        <form wire:submit="invite" class="space-y-4" x-data="{ role: @entangle('role') }">
            <x-ui.field label="{{ __('Full name') }}" for="m-name" error="name"><input id="m-name" wire:model="name" class="input" required></x-ui.field>
            <x-ui.field label="{{ __('Email') }}" for="m-email" error="email" hint="{{ __('If the account exists, we\'ll add a club role to it.') }}"><input id="m-email" type="email" wire:model="email" class="input" required></x-ui.field>
            <div>
                <span class="label">{{ __('Role') }}</span>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($roles as $r)
                        <button type="button" x-on:click="role = @js($r->value)" :class="role === @js($r->value) ? 'border-brand-500 bg-brand-500/10 text-fg' : 'border-line text-muted hover:text-fg'" class="rounded-xl border px-3 py-2 text-sm font-semibold transition">{{ $r->label() }}</button>
                    @endforeach
                </div>
            </div>
            <div x-show="role === 'parent' || role === 'player'" x-transition>
                <x-ui.field error="player_ids">
                    <span class="label" x-text="role === 'parent' ? @js(__('Children (players)')) : @js(__('Player profile'))"></span>
                    <select wire:model="player_ids" class="input h-36" multiple>
                        @foreach ($players as $p)<option value="{{ $p->id }}">#{{ $p->number }} {{ $p->full_name }}</option>@endforeach
                    </select>
                </x-ui.field>
            </div>
            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" x-on:click="$dispatch('close-modal', 'invite')">{{ __('Close') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ __('Add') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
