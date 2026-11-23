{{-- Small jersey-number badge next to a player; "?" when the number isn't known. --}}
@props(['player' => null, 'name' => true])
@php $number = $player?->number; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex min-w-0 items-center gap-1.5']) }}>
    <span @class([
        'inline-grid h-5 min-w-5 shrink-0 place-items-center rounded-md px-1 font-display text-[11px] font-bold leading-none tabular',
        'bg-brand-500/15 text-brand-400 ring-1 ring-brand-500/30' => $number !== null,
        'bg-warning-500/15 text-warning-400 ring-1 ring-warning-500/40' => $number === null,
    ]) title="{{ $number !== null ? __('Jersey number') : __('Number unknown') }}">{{ $number ?? '?' }}</span>
    @if ($name && $player && ! $player->isAnonymous())<span class="truncate">{{ $player->short_name }}</span>@endif
</span>
