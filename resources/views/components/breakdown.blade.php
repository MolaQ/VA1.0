@props(['title', 'rows', 'labels' => []])
@php $max = max(1, (int) $rows->max('views')); @endphp
<x-ui.card :title="$title" padding="p-3">
    @forelse ($rows as $row)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-lg px-3 py-2 text-sm">
            <span class="absolute inset-y-0 left-0 rounded-lg bg-brand-500/12 transition-all duration-700" style="width: {{ $row['views'] / $max * 100 }}%"></span>
            <span class="relative min-w-0 flex-1 truncate">{{ $labels[$row['label']] ?? $row['label'] }}</span>
            <span class="relative text-xs text-muted tabular" title="{{ __('Visitors') }}">{{ $row['visitors'] }}</span>
            <span class="relative w-12 text-right font-semibold tabular">{{ number_format($row['views'], 0, ',', ' ') }}</span>
        </div>
    @empty
        <p class="px-3 py-4 text-sm text-muted">{{ __('No data yet.') }}</p>
    @endforelse
</x-ui.card>
