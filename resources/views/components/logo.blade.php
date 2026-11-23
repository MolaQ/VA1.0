@props(['compact' => false])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <span class="relative grid size-9 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-accent-400 text-white shadow-lg shadow-brand-500/30">
        <x-icon name="volleyball" class="size-5" />
        <span class="absolute -right-1 -top-1 size-2.5 rounded-full bg-volley-500 ring-2 ring-bg"></span>
    </span>
    @unless ($compact)
        <span class="leading-none">
            <span class="block font-display text-xl font-bold tracking-wider">VVA</span>
            <span class="block text-[10px] font-semibold tracking-[0.2em] text-muted uppercase">Volleyball Video Analyze</span>
        </span>
    @endunless
</span>
