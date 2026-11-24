@props(['icon' => 'sparkles', 'title', 'text' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-line-strong px-6 py-12 text-center']) }}>
    <span class="grid size-12 place-items-center rounded-2xl bg-brand-500/10 text-brand-400"><x-icon :name="$icon" class="size-6" /></span>
    <h3 class="font-display text-lg font-semibold">{{ $title }}</h3>
    @if ($text)<p class="max-w-md text-sm text-muted">{{ $text }}</p>@endif
    {{ $slot }}
</div>
