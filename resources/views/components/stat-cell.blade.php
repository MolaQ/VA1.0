@props(['value', 'player' => null, 'skill', 'filter' => 'all'])
@if ((int) $value === 0)
    <span class="text-subtle">{{ $value }}</span>
@else
    <button type="button" class="stat-cell" x-on:click="playStat({{ $player ?? 'null' }}, @js($skill), @js($filter))" title="{{ __('Play actions') }}">{{ $value }}</button>
@endif
