{{--
    2D court under the video: players as dots (jersey number, triangle = where the body faces),
    following the video time. Positions come from the AI analyzer; the analyst only fixes the
    jersey number / team of a dot and marks where a serve landed or a pass went.
    Screen layout like the zone chart: our zones 5 6 1 / 4 3 2 on the left half when we play near the camera.
--}}
@php
    $zones = [
        // [screen x, screen y, zone] - left half (team facing right), right half (team facing left)
        [3, 1.5, 5], [7.5, 1.5, 4], [3, 4.5, 6], [7.5, 4.5, 3], [3, 7.5, 1], [7.5, 7.5, 2],
        [15, 7.5, 5], [10.5, 7.5, 4], [15, 4.5, 6], [10.5, 4.5, 3], [15, 1.5, 1], [10.5, 1.5, 2],
    ];
@endphp
<div wire:ignore x-data="court2d(@js(['sets' => $courtSets, 'canCode' => $canCode]))" class="no-print border-t border-line px-4 py-3">
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <span class="label !mb-0">{{ __('Court view') }}</span>
        <div class="flex gap-0.5 rounded-lg bg-surface-2 p-0.5 text-xs font-semibold">
            <button type="button" x-on:click="setMode('rotation')" :class="mode === 'rotation' ? 'bg-surface-3 text-fg shadow' : 'text-muted hover:text-fg'" class="rounded-md px-2 py-1">{{ __('Rotation') }}</button>
            <button type="button" x-on:click="setMode('live')" :class="mode === 'live' ? 'bg-surface-3 text-fg shadow' : 'text-muted hover:text-fg'" class="rounded-md px-2 py-1">{{ __('Live (beta)') }}</button>
        </div>
        <span class="text-xs text-muted" x-show="status() === 'rotation'">
            Set <b x-text="rotInfo().set"></b> · {{ __('Rotation') }} <b x-text="rotInfo().rot"></b> (I: #<span x-text="rotInfo().zone1"></span>) ·
            <b class="tabular" x-text="rotInfo().us + ':' + rotInfo().them"></b> ·
            <span x-text="rotInfo().serve === 'us' ? @js(__('we serve')) : (rotInfo().serve === 'them' ? @js(__('we receive')) : '')"></span>
        </span>
        <span class="text-xs text-muted" x-show="status() === 'no-rotation'">{{ __('No rotation yet: enter the starting lineup of the set below.') }}</span>
        <span class="text-xs text-muted" x-show="status() === 'none'">{{ __('No player positions for this moment yet.') }}</span>
        <span class="text-xs text-muted" x-show="status() === 'ai'">{{ __('Positions from the AI analysis.') }}</span>
        <div class="ml-auto flex items-center gap-1.5">
            @if ($canCode)
                <button type="button" x-on:click="toggleSide()" class="rounded-lg bg-surface-2 px-2 py-1 text-xs font-semibold hover:bg-surface-3" title="{{ __('Which half of the picture we play on in this set') }}">
                    {{ __('Swap sides') }} ⇄
                </button>
                <button type="button" x-on:click="ball.open ? closeBall() : openBall()" :class="ball.open ? 'bg-volley-500 text-white' : 'bg-surface-2 hover:bg-surface-3'" class="rounded-lg px-2 py-1 text-xs font-semibold">
                    {{ __('Ball point') }}
                </button>
            @endif
        </div>
    </div>

    <svg x-ref="court" viewBox="-1.6 -1.4 21.2 12.3" class="court-2d mx-auto block w-full max-w-2xl touch-none select-none"
         x-on:pointerdown="onPointerDown($event)" role="img" aria-label="{{ __('Court view') }}">
        <rect x="-1.6" y="-1.4" width="21.2" height="12.3" class="court-out" />
        <rect x="0" y="0" width="18" height="9" class="court-in" />
        <line x1="6" y1="0" x2="6" y2="9" class="court-line" />
        <line x1="12" y1="0" x2="12" y2="9" class="court-line" />
        <line x1="6" y1="-1" x2="6" y2="0" class="court-line court-line-dash" />
        <line x1="6" y1="9" x2="6" y2="10" class="court-line court-line-dash" />
        <line x1="12" y1="-1" x2="12" y2="0" class="court-line court-line-dash" />
        <line x1="12" y1="9" x2="12" y2="10" class="court-line court-line-dash" />
        <line x1="9" y1="-0.9" x2="9" y2="9.9" class="court-net" />
        <circle cx="9" cy="-0.9" r="0.15" class="court-post" />
        <circle cx="9" cy="9.9" r="0.15" class="court-post" />
        @foreach ($zones as [$zx, $zy, $z])
            <text x="{{ $zx }}" y="{{ $zy }}" text-anchor="middle" dominant-baseline="central" font-size="0.9" class="court-zone">{{ $z }}</text>
        @endforeach
        <text x="4.5" y="10.45" text-anchor="middle" font-size="0.55" class="court-side" x-text="ourSide(t ?? 0) === 'near' ? @js(__('Us')) : @js(__('Opponent'))"></text>
        <text x="13.5" y="10.45" text-anchor="middle" font-size="0.55" class="court-side" x-text="ourSide(t ?? 0) === 'near' ? @js(__('Opponent')) : @js(__('Us'))"></text>
        <g x-ref="ball"></g>
        <g x-ref="dots"></g>
    </svg>

    @if ($canCode)
        {{-- AI lost the number or is unsure: name the dot (the whole track gets it) --}}
        <div x-show="selected && mode === 'live'" x-cloak x-transition class="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-surface-2 px-3 py-2 text-sm">
            <span class="text-muted">{{ __('Who is this?') }}</span>
            <input type="number" min="0" max="99" :value="selected?.n ?? ''" x-on:input="selected.n = $event.target.value" x-on:keydown.enter.prevent="saveLabel()" class="input !w-20 !px-2 !py-1 tabular" placeholder="#" aria-label="{{ __('Jersey number') }}">
            <button type="button" x-on:click="selected.team = 'us'" :class="selected?.team === 'us' ? 'bg-brand-500 text-white' : 'bg-surface-3'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ __('Us') }}</button>
            <button type="button" x-on:click="selected.team = 'them'" :class="selected?.team === 'them' ? 'bg-danger-500 text-white' : 'bg-surface-3'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ __('Opponent') }}</button>
            <x-ui.button size="sm" icon="check" x-on:click="saveLabel()">{{ __('Save') }}</x-ui.button>
            <button type="button" x-on:click="selected = null" class="text-xs text-muted hover:text-fg">{{ __('Cancel') }}</button>
            <span class="w-full text-xs text-subtle">{{ __('The number is kept for as long as the AI follows this player.') }}</span>
        </div>

        {{-- Where the serve landed / where the pass went: reception quality from the distance to the target --}}
        <div x-show="ball.open" x-cloak x-transition class="mt-2 space-y-2 rounded-xl bg-surface-2 px-3 py-2 text-sm">
            <div class="flex flex-wrap items-center gap-1.5">
                <template x-for="a in ball.actions" :key="a.id">
                    <button type="button" x-on:click="pickAction(a)" :class="ball.action?.id === a.id ? 'bg-volley-500 text-white' : 'bg-surface-3 hover:bg-line'" class="rounded-lg px-2 py-1 text-xs font-semibold" x-text="a.label"></button>
                </template>
                <span x-show="! ball.actions.length" class="text-xs text-muted">{{ __('No serve or reception within 8 s of this moment.') }}</span>
            </div>
            <p class="text-xs text-muted" x-show="ball.action">
                <span x-show="ball.step === 'from'">{{ __('Click where the ball was received.') }}</span>
                <span x-show="ball.step === 'to' && ball.action?.skill === 'reception'">{{ __('Click where the pass went (the ring = 1 m around the target).') }}</span>
                <span x-show="ball.step === 'to' && ball.action?.skill === 'serve'">{{ __('Click where the serve landed or was received.') }}</span>
                <span x-show="! ball.step">{{ __('Saved.') }} <span x-show="ball.zone">{{ __('Zone') }} <b x-text="ball.zone"></b>.</span></span>
            </p>
            <div x-show="ball.suggested" class="flex items-center gap-2">
                <span>{{ __('Suggested reception grade') }}: <b class="font-display text-lg" x-text="ball.suggested"></b></span>
                <x-ui.button size="sm" variant="secondary" x-on:click="applySuggested()">{{ __('Apply') }}</x-ui.button>
            </div>
        </div>
    @endif
</div>
