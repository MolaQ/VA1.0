@props(['tone' => 'neutral', 'pulse' => false])
@php
    $tones = [
        'neutral' => 'bg-surface-3 text-muted',
        'brand' => 'bg-brand-500/15 text-brand-400',
        'accent' => 'bg-accent-500/15 text-accent-400',
        'success' => 'bg-success-500/15 text-success-400',
        'danger' => 'bg-danger-500/15 text-danger-400',
        'warning' => 'bg-warning-500/15 text-warning-400',
        'energy' => 'bg-volley-500/15 text-volley-400',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold '.($tones[$tone] ?? $tones['neutral'])]) }}>
    @if ($pulse)<span class="size-1.5 rounded-full bg-current animate-pulse-soft"></span>@endif
    {{ $slot }}
</span>
