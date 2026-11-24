@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end justify-between gap-4 animate-fade-up']) }}>
    <div class="min-w-0">
        @if ($eyebrow)<p class="mb-1 text-xs font-semibold tracking-[0.18em] text-brand-400 uppercase">{{ $eyebrow }}</p>@endif
        <h1 class="font-display text-3xl font-bold tracking-wide sm:text-4xl">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 text-sm text-muted sm:text-base">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
