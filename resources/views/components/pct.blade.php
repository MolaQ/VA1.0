@props(['value', 'good' => 0.5, 'bad' => 0])
@php
    $class = match (true) {
        $value === null => 'text-subtle',
        $value >= $good => 'text-success-400 font-semibold',
        $value < $bad => 'text-danger-400',
        default => '',
    };
@endphp
<span class="{{ $class }}">{{ \App\Stats\PlayerStatLine::percent($value) }}</span>
