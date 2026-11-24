{{-- One touch inside the point-by-point list: click to jump the video; coaches edit / delete. --}}
@php
    $edit = [
        'id' => $a->id,
        'pid' => $a->player_id,
        'skill' => $a->skill->value,
        'grade' => $a->grade->value,
        'set' => $a->set?->number,
        'rally' => $a->rally,
        't' => $a->video_time,
        'label' => trim(($a->player?->numberLabel() ?? '?').' · '.$a->skill->label().' '.$a->grade->value),
    ];
@endphp
<li wire:key="ra-{{ $a->id }}" class="group flex items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-surface-2" :class="isEditing({{ $a->id }}) ? 'bg-brand-500/10 ring-1 ring-brand-500/40' : ''">
    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
            @if ($a->video_time !== null) x-on:click="seek({{ $a->video_time }})" title="{{ __('Jump to this moment') }}" @else disabled title="{{ __('No video time') }}" @endif>
        <span @class(['w-11 shrink-0 text-xs tabular', 'text-muted' => $a->video_time !== null, 'text-warning-400' => $a->video_time === null])>{{ $a->video_time !== null ? \App\Support\YouTube::formatTime($a->video_time) : __('no time') }}</span>
        <span class="grid size-6 shrink-0 place-items-center rounded-md text-xs font-bold {{ $gradeStyles[$a->grade->value] }}" title="{{ $a->grade->label() }}">{{ $a->grade->value }}</span>
        <span class="w-20 shrink-0 truncate font-medium">{{ $a->skill->label() }}</span>
        <x-jersey :player="$a->player" class="overflow-hidden" />
        @if ($a->zone_from || $a->zone_to)<span class="shrink-0 text-xs text-subtle">{{ $a->zone_from }}{{ $a->zone_from && $a->zone_to ? '→' : '' }}{{ $a->zone_to }}</span>@endif
    </button>
    @if ($a->needs_review)
        <span class="shrink-0 text-warning-400" title="{{ __('To clarify') }}: {{ $a->reviewReason() }}"><x-icon name="flag" class="size-4" /></span>
    @endif
    @if ($a->source === \App\Enums\ActionSource::Ai)
        <x-ui.badge tone="accent" class="shrink-0 max-sm:hidden" title="{{ __('Confidence') }} {{ $a->confidence !== null ? round($a->confidence * 100).'%' : '–' }}">AI</x-ui.badge>
    @endif
    @if ($canCode)
        <button type="button" x-on:click="startEdit(@js($edit))" class="shrink-0 rounded-lg p-1 text-subtle transition hover:text-brand-400 sm:opacity-0 sm:group-hover:opacity-100" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="size-4" /></button>
        <button type="button" wire:click="deleteAction({{ $a->id }})" wire:confirm="{{ __('Delete this action?') }}" class="shrink-0 rounded-lg p-1 text-subtle transition hover:text-danger-400 sm:opacity-0 sm:group-hover:opacity-100" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
    @endif
</li>
