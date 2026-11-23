@props(['label', 'value', 'hint' => null, 'icon' => null, 'tone' => 'brand'])
@php
    $tones = [
        'brand' => 'from-brand-500/20 to-accent-500/5 text-brand-400',
        'energy' => 'from-volley-500/20 to-volley-500/5 text-volley-400',
        'success' => 'from-success-500/20 to-success-500/5 text-success-400',
        'danger' => 'from-danger-500/20 to-danger-500/5 text-danger-400',
    ];
@endphp
<div {{ $attributes->merge(['class' => 'card group relative overflow-hidden p-5 animate-fade-up transition hover:-translate-y-0.5 hover:border-line-strong']) }}>
    <div class="absolute -right-6 -top-6 size-24 rounded-full bg-gradient-to-br {{ $tones[$tone] ?? $tones['brand'] }} blur-2xl opacity-70 transition group-hover:opacity-100"></div>
    <div class="relative flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ $label }}</p>
            <p class="mt-2 font-display text-3xl font-bold tabular"
               x-data="{ shown: 0, target: {{ is_numeric($value) ? (float) $value : 'null' }} }"
               x-init="if (target !== null) { const t0 = performance.now(); const step = (t) => { const p = Math.min(1, (t - t0) / 700); shown = Math.round(target * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step) }; requestAnimationFrame(step) }"
               x-text="target !== null ? shown : @js((string) $value)">{{ $value }}</p>
            @if ($hint)<p class="mt-1 text-xs text-subtle">{{ $hint }}</p>@endif
        </div>
        @if ($icon)
            <span class="grid size-10 place-items-center rounded-xl bg-gradient-to-br {{ $tones[$tone] ?? $tones['brand'] }}">
                <x-icon :name="$icon" class="size-5" />
            </span>
        @endif
    </div>
</div>
