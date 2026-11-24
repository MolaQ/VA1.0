{{--
    One touch in a list of the match page (timeline, coding, point by point).
    Click: jump the video there. Coaches: pencil opens live editing in place: every change
    is saved at once ($wire.patchAction) and confirmed with a toast, no save button.
    Needs $a, $canCode, $gradeStyles; optional $showSet, $flag (a "leave to clarify" button). Roster / skills / grades come
    from Alpine.store('codes'), so a long list does not carry a form per touch.
--}}
@php $showSet ??= false; $flag ??= false; @endphp
<li wire:key="ar-{{ $a->id }}" x-data="{ edit: false, grade: @js($a->grade->value) }" class="rounded-lg transition hover:bg-surface-2" :class="edit ? 'bg-brand-500/10 ring-1 ring-brand-500/40' : ''">
    <div class="group flex items-center gap-2 px-2 py-1.5">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm"
                @if ($a->video_time !== null) x-on:click="jump({{ $a->video_time }})" title="{{ __('Jump to this moment') }}" @else disabled title="{{ __('No video time') }}" @endif>
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
        @if ($showSet && $a->set)
            <span class="shrink-0 text-xs text-subtle">S{{ $a->set->number }}{{ $a->rally ? ' · #'.$a->rally : '' }}</span>
        @endif
        @if ($canCode && $flag && ! $a->needs_review)
            <button type="button" wire:click="flagAction({{ $a->id }})" class="shrink-0 rounded-lg p-1 text-subtle opacity-0 transition hover:text-warning-400 group-hover:opacity-100" title="{{ __('Leave to clarify') }}" aria-label="{{ __('Leave to clarify') }}"><x-icon name="flag" class="size-4" /></button>
        @endif
        @if ($canCode)
            <button type="button" x-on:click="edit = ! edit" :class="edit ? 'text-brand-400 opacity-100' : 'text-subtle sm:opacity-0 sm:group-hover:opacity-100'" class="shrink-0 rounded-lg p-1 transition hover:text-brand-400" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="size-4" /></button>
            <button type="button" wire:click="deleteAction({{ $a->id }})" wire:confirm="{{ __('Delete this action?') }}" class="shrink-0 rounded-lg p-1 text-subtle transition hover:text-danger-400 sm:opacity-0 sm:group-hover:opacity-100" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
        @endif
    </div>

    @if ($canCode)
        <template x-if="edit">
            <div class="space-y-1.5 px-2 pb-2" x-on:keydown.escape.stop="edit = false">
                <div class="grid grid-cols-2 gap-1.5">
                    <select x-on:change="$wire.patchAction({{ $a->id }}, 'player', $event.target.value)" class="input !px-2 !py-1 text-sm tabular" aria-label="{{ __('Player') }}">
                        <option value="">? {{ __('Unknown') }}</option>
                        <template x-for="p in $store.codes.roster" :key="p.id">
                            <option :value="p.id" x-text="p.label" :selected="p.id === {{ $a->player_id ?? 'null' }}"></option>
                        </template>
                    </select>
                    <select x-on:change="$wire.patchAction({{ $a->id }}, 'skill', $event.target.value)" class="input !px-2 !py-1 text-sm" aria-label="{{ __('Skill') }}">
                        <template x-for="s in $store.codes.skills" :key="s.value">
                            <option :value="s.value" x-text="s.label" :selected="s.value === @js($a->skill->value)"></option>
                        </template>
                    </select>
                </div>
                <div class="grid grid-cols-6 gap-1">
                    @foreach (\App\Enums\Grade::cases() as $g)
                        <button type="button" x-on:click="grade = @js($g->value); $wire.patchAction({{ $a->id }}, 'grade', grade)" title="{{ $g->label() }}"
                                :class="grade === @js($g->value) ? 'ring-2 ring-fg' : 'opacity-50 hover:opacity-100'"
                                class="rounded-lg py-1 font-display text-base font-bold transition active:scale-95 {{ $gradeStyles[$g->value] }}">{{ $g->value }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
                    <label>Set</label>
                    <input type="number" min="1" max="5" value="{{ $a->set?->number }}" x-on:change="$wire.patchAction({{ $a->id }}, 'set', $event.target.value)" class="input !w-12 !px-1.5 !py-0.5 text-sm tabular">
                    <label>{{ __('Rally') }}</label>
                    <input type="number" min="1" max="999" value="{{ $a->rally }}" x-on:change="$wire.patchAction({{ $a->id }}, 'rally', $event.target.value)" class="input !w-16 !px-1.5 !py-0.5 text-sm tabular">
                    <button type="button" x-on:click="const t = currentTime(); if (t !== null) $wire.patchAction({{ $a->id }}, 'time', String(Math.round(t * 100) / 100))"
                            class="ml-auto inline-flex items-center gap-1 rounded-lg bg-surface-2 px-2 py-1 font-semibold text-fg hover:bg-surface-3" title="{{ __('Set this touch to the current video time') }}">
                        <x-icon name="clock" class="size-3.5" /> {{ __('Time from video') }}
                    </button>
                    <button type="button" x-on:click="edit = false" class="rounded-lg px-2 py-1 font-semibold text-muted hover:text-fg">{{ __('Done') }}</button>
                </div>
            </div>
        </template>
    @endif
</li>
