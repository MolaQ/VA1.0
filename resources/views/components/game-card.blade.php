@props(['game'])
@php
    $status = $game->analysis_status;
    $tone = match ($status) {
        \App\Enums\AnalysisStatus::Completed => 'success',
        \App\Enums\AnalysisStatus::Processing, \App\Enums\AnalysisStatus::Queued => 'accent',
        \App\Enums\AnalysisStatus::Failed => 'danger',
        default => 'neutral',
    };
    $won = $game->isWon();
@endphp
<a href="{{ route('games.show', $game) }}" wire:navigate
   {{ $attributes->merge(['class' => 'card group block overflow-hidden transition duration-300 hover:-translate-y-1 hover:border-line-strong hover:shadow-xl hover:shadow-brand-500/10 animate-fade-up']) }}>
    <div class="relative aspect-video overflow-hidden bg-surface-3">
        @if ($game->youtube_id)
            <img src="{{ \App\Support\YouTube::thumbnail($game->youtube_id) }}" alt="" loading="lazy" onerror="this.remove()"
                 class="size-full object-cover opacity-80 transition duration-500 group-hover:scale-105 group-hover:opacity-100">
        @else
            <div class="grid size-full place-items-center text-subtle"><x-icon name="video" class="size-10" /></div>
        @endif
        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/10 to-transparent"></div>
        <span class="absolute left-3 top-3"><x-ui.badge :tone="$tone" :pulse="$status->isActive()">{{ $status->label() }}@if ($status === \App\Enums\AnalysisStatus::Processing) · {{ $game->analysis_progress }}%@endif</x-ui.badge></span>
        <span class="absolute right-3 top-3 grid size-10 place-items-center rounded-full bg-white/15 text-white opacity-0 backdrop-blur transition group-hover:opacity-100"><x-icon name="play" class="size-4" /></span>
        <div class="absolute inset-x-3 bottom-3 flex items-end justify-between gap-3 text-white">
            <div class="min-w-0">
                <p class="truncate font-display text-lg font-semibold leading-tight">{{ $game->team->name }} <span class="text-white/60">vs</span> {{ $game->opponent }}</p>
                <p class="text-xs text-white/70">{{ $game->played_at->translatedFormat('j M Y, H:i') }}</p>
            </div>
            <span @class([
                'rounded-lg px-2.5 py-1 font-display text-xl font-bold tabular',
                'bg-success-500/90' => $won === true,
                'bg-danger-500/90' => $won === false,
                'bg-white/15' => $won === null,
            ])>{{ $game->result() }}</span>
        </div>
    </div>
</a>
