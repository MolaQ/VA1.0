<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
    <button class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-semibold transition hover:bg-surface-2" x-on:click="open = ! open">
        <span class="grid size-7 place-items-center rounded-lg bg-surface-3 text-[11px] font-bold">{{ $current?->initials() }}</span>
        <span class="hidden max-w-40 truncate sm:inline">{{ $current?->name }}</span>
        <x-icon name="chevron-down" class="size-4 text-muted" />
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right class="card absolute right-0 mt-2 w-64 p-1.5">
        <p class="px-3 py-2 text-[11px] font-semibold tracking-[0.16em] text-subtle uppercase">{{ __('Your clubs') }}</p>
        @foreach ($clubs as $club)
            <button wire:click="switchTo({{ $club->id }})" class="nav-link w-full" @if ($current?->id === $club->id) data-current @endif>
                <span class="grid size-7 place-items-center rounded-lg bg-surface-3 text-[11px] font-bold">{{ $club->initials() }}</span>
                <span class="truncate">{{ $club->name }}</span>
            </button>
        @endforeach
    </div>
</div>
