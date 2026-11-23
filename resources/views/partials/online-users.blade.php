{{-- Live list of club members signed in right now (Reverb presence channel). --}}
<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-show="$store.online.members.length" x-cloak x-transition>
    <button type="button" x-on:click="open = ! open" class="flex h-10 items-center gap-2 rounded-xl px-2.5 text-sm font-semibold text-muted transition hover:bg-surface-2 hover:text-fg" :aria-label="@js(__('Online now'))">
        <span class="relative flex size-2.5">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-success-400 opacity-60"></span>
            <span class="relative inline-flex size-2.5 rounded-full bg-success-500"></span>
        </span>
        <span class="tabular" x-text="$store.online.members.length"></span>
        <span class="hidden -space-x-2 md:flex">
            <template x-for="m in $store.online.members.slice(0, 3)" :key="m.id">
                <span class="grid size-7 place-items-center rounded-full bg-gradient-to-br from-brand-500 to-accent-500 text-[10px] font-bold text-white ring-2 ring-bg" x-text="m.initials"></span>
            </template>
        </span>
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right class="card absolute right-0 z-50 mt-2 w-64 p-1.5">
        <p class="px-3 py-2 text-[11px] font-semibold tracking-[0.16em] text-subtle uppercase">{{ __('Online now') }}</p>
        <template x-for="m in $store.online.members" :key="m.id">
            <div class="flex items-center gap-3 rounded-xl px-3 py-2">
                <span class="grid size-8 place-items-center rounded-lg bg-surface-3 text-xs font-bold" x-text="m.initials"></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold" x-text="m.name"></span>
                    <span class="block text-xs text-muted" x-text="m.role"></span>
                </span>
                <span class="size-2 rounded-full bg-success-500"></span>
            </div>
        </template>
    </div>
</div>
