@props(['title' => null, 'subtitle' => null, 'padding' => 'p-5 sm:p-6'])
<section {{ $attributes->merge(['class' => 'card animate-fade-up']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
            <div>
                @if ($title)<h2 class="font-display text-lg font-semibold tracking-wide">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="text-sm text-muted">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div class="{{ $padding }}">{{ $slot }}</div>
</section>
