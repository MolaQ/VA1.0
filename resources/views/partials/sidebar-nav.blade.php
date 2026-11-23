<nav class="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-4">
    <div class="space-y-1">
        @foreach ($nav as $item)
            <a href="{{ route($item['route']) }}" wire:navigate class="nav-link" @if (request()->routeIs($item['match'])) data-current @endif>
                <x-icon :name="$item['icon']" class="size-5" /> {{ $item['label'] }}
            </a>
        @endforeach
    </div>

    @if ($adminNav)
        <div class="space-y-1">
            <p class="px-3 pb-1 text-[11px] font-semibold tracking-[0.16em] text-subtle uppercase">{{ __('Club') }}</p>
            @foreach ($adminNav as $item)
                <a href="{{ route($item['route']) }}" wire:navigate class="nav-link" @if (request()->routeIs($item['match'])) data-current @endif>
                    <x-icon :name="$item['icon']" class="size-5" /> {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($platformNav)
        <div class="space-y-1">
            <p class="px-3 pb-1 text-[11px] font-semibold tracking-[0.16em] text-subtle uppercase">{{ __('Platform') }}</p>
            @foreach ($platformNav as $item)
                <a href="{{ route($item['route']) }}" wire:navigate class="nav-link" @if (request()->routeIs($item['match'])) data-current @endif>
                    <x-icon :name="$item['icon']" class="size-5" /> {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($club)
        <div class="mt-auto rounded-2xl border border-line bg-gradient-to-br from-brand-500/10 to-accent-500/5 p-4">
            <p class="flex items-center gap-2 text-sm font-semibold"><x-icon name="sparkles" class="size-4 text-accent-400" /> {{ __('AI analysis') }}</p>
            <p class="mt-1 text-xs text-muted">{{ __('Add a match with a YouTube link and the stats will appear automatically.') }}</p>
        </div>
    @endif
</nav>
