@php $current = app()->getLocale(); @endphp
<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
    <button type="button" x-on:click="open = ! open" class="flex h-10 items-center gap-1.5 rounded-xl px-2.5 text-sm font-semibold text-muted uppercase transition hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Language') }}">
        <x-icon name="globe" class="size-4" /> {{ $current }}
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right class="card absolute right-0 z-50 mt-2 w-44 p-1.5">
        @foreach (\App\Support\Locales::supported() as $code => $name)
            <a href="{{ route('locale.switch', $code) }}" class="nav-link" @if ($code === $current) data-current @endif>
                <span class="w-6 text-xs font-bold uppercase">{{ $code }}</span> {{ $name }}
            </a>
        @endforeach
    </div>
</div>
