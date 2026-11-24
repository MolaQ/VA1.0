@php
    $gradeStyles = [
        '#' => 'bg-success-500 text-white', '+' => 'bg-success-500/20 text-success-400', '!' => 'bg-surface-3 text-fg',
        '-' => 'bg-warning-500/20 text-warning-400', '/' => 'bg-volley-500/20 text-volley-400', '=' => 'bg-danger-500 text-white',
    ];
@endphp
<div x-data="ralliesPanel(@js($roster), @js($skills), @js($grades))" class="relative">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-1.5 px-3 pb-2 pt-3">
        <p class="mr-auto text-xs text-muted">{{ __('Every rally from the serve on. Click a touch to jump in the video.') }}</p>
        <button type="button" x-on:click="expandAll(true)" class="rounded-lg px-2 py-1 text-xs font-semibold text-muted hover:bg-surface-2 hover:text-fg">{{ __('Expand all') }}</button>
        <button type="button" x-on:click="expandAll(false)" class="rounded-lg px-2 py-1 text-xs font-semibold text-muted hover:bg-surface-2 hover:text-fg">{{ __('Collapse all') }}</button>
        <button type="button" x-on:click="follow = ! follow; followed = null" :class="follow ? 'bg-brand-500 text-white' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold" title="{{ __('Open and scroll to the rally the video is playing') }}">
            <x-icon name="eye" class="size-3.5" /> {{ __('Follow video') }}
        </button>
    </div>

    @forelse ($sets as $set)
        @php
            $rallies = $set['rallies'];
            $coded = $rallies !== [] ? end($rallies)['after'] : [0, 0];
            $mismatch = $set['score_us'] + $set['score_them'] > 0 && $coded !== [$set['score_us'], $set['score_them']];
        @endphp
        <section wire:key="rs-{{ $set['number'] }}" x-data="{ shut: false }" class="pb-2">
            <header class="z-10 xl:sticky xl:top-0 flex items-center gap-2 border-y border-line bg-surface/95 px-3 py-2 backdrop-blur">
                <button type="button" x-on:click="shut = ! shut" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <x-icon name="chevron-down" class="size-4 shrink-0 text-muted transition" ::class="shut ? '-rotate-90' : ''" />
                    <span class="font-display text-base font-bold">Set {{ $set['number'] }}</span>
                    <span class="rounded bg-surface-3 px-1.5 py-0.5 text-xs font-semibold tabular">{{ $set['score_us'] }}:{{ $set['score_them'] }}</span>
                    <span class="text-xs text-muted">{{ trans_choice(':count rally|:count rallies', count($rallies)) }}</span>
                </button>
                @if ($mismatch)
                    <span class="shrink-0 text-xs text-warning-400" title="{{ __('The coded rallies do not add up to the set score: some points are missing or have no winner.') }}">{{ __('coded') }} {{ $coded[0] }}:{{ $coded[1] }}</span>
                @endif
            </header>

            <ol x-show="! shut" class="divide-y divide-line">
                @foreach ($rallies as $r)
                    @php
                        $key = $set['number'].'-'.$r['number'];
                        $last = $r['actions']->last();
                    @endphp
                    <li wire:key="rr-{{ $key }}" data-rally="{{ $key }}" data-start="{{ $r['start'] }}" data-end="{{ $r['end'] }}"
                        x-data="{ open: false }"
                        x-on:vva-rallies-expand.window="open = $event.detail"
                        x-on:vva-rally-follow.window="if ($event.detail === @js($key)) open = true"
                        :class="isPlaying(@js($r['start']), @js($r['end'])) ? 'bg-brand-500/10' : ''"
                        class="scroll-mt-12 transition">
                        {{-- Rally summary --}}
                        <div class="flex items-center gap-2 px-2 py-2">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                <x-icon name="chevron-down" class="size-4 shrink-0 text-muted transition" ::class="! open ? '-rotate-90' : ''" />
                                <span class="w-7 shrink-0 text-xs tabular text-subtle">#{{ $r['number'] }}</span>
                                <span @class([
                                    'grid size-5 shrink-0 place-items-center rounded-full',
                                    'bg-brand-500/20 text-brand-400' => $r['server'] === 'us',
                                    'bg-surface-3 text-muted' => $r['server'] !== 'us',
                                ]) title="{{ match ($r['server']) { 'us' => __('We served'), 'them' => __('Opponent served'), default => __('Server unknown') } }}">
                                    <x-icon name="volleyball" class="size-3" />
                                </span>
                                <span class="shrink-0 text-sm tabular">
                                    <span class="text-muted">{{ $r['before'][0] }}:{{ $r['before'][1] }}</span>
                                    <span class="text-subtle">→</span>
                                    <span @class(['font-bold', 'text-success-400' => $r['winner'] === 'us'])>{{ $r['after'][0] }}</span><span class="text-muted">:</span><span @class(['font-bold', 'text-danger-400' => $r['winner'] === 'them'])>{{ $r['after'][1] }}</span>
                                </span>
                                <span @class([
                                    'min-w-0 truncate text-xs',
                                    'text-success-400' => $r['winner'] === 'us',
                                    'text-danger-400' => $r['winner'] === 'them',
                                    'text-warning-400' => $r['winner'] === null,
                                ])>{{ $r['outcome'] }}</span>
                            </button>
                            @if ($r['review'])
                                <span class="shrink-0 text-warning-400" title="{{ __('To clarify') }}"><x-icon name="flag" class="size-3.5" /></span>
                            @endif
                            <span class="shrink-0 text-xs tabular text-subtle" title="{{ __('Touches') }}">{{ $r['actions']->count() }}</span>
                            @if ($r['start'] !== null)
                                <button type="button" x-on:click="seek({{ $r['start'] }})" class="inline-flex shrink-0 items-center gap-1 rounded-lg px-1.5 py-0.5 text-xs tabular text-muted hover:bg-surface-3 hover:text-fg" title="{{ __('Play the rally') }}">
                                    <x-icon name="play" class="size-3" />{{ \App\Support\YouTube::formatTime($r['start']) }}
                                </button>
                            @else
                                <span class="w-12 shrink-0 text-right text-xs text-subtle" title="{{ __('No video time') }}">–</span>
                            @endif
                        </div>

                        {{-- Rally detail: every touch in order --}}
                        <div x-show="open" x-cloak class="pb-2 pl-6 pr-1">
                            <ol class="border-l border-line pl-2">
                                @unless ($r['hasServe'])
                                    <li class="flex items-center gap-2 px-2 py-1.5 text-sm text-muted">
                                        <span class="w-11 shrink-0 text-xs">–</span>
                                        <span class="grid size-6 shrink-0 place-items-center rounded-md bg-surface-3"><x-icon name="volleyball" class="size-3.5" /></span>
                                        <span>{{ match ($r['server']) { 'them' => __('Opponent serve'), 'us' => __('Our serve (not coded)'), default => __('Serve (unknown who)') } }}</span>
                                    </li>
                                @endunless
                                @foreach ($r['actions'] as $a)
                                    @include('livewire.games.partials.action-row', ['a' => $a])
                                @endforeach
                                @if ($r['hidden'] > 0)
                                    <li class="px-2 py-1 text-xs text-subtle">{{ trans_choice('+ :count touch of other players|+ :count touches of other players', $r['hidden']) }}</li>
                                @endif
                                <li @class([
                                    'flex items-center gap-2 px-2 py-1.5 text-sm font-semibold',
                                    'text-success-400' => $r['winner'] === 'us',
                                    'text-danger-400' => $r['winner'] === 'them',
                                    'text-warning-400' => $r['winner'] === null,
                                ])>
                                    <x-icon :name="$r['winner'] === null ? 'clock' : 'flag'" class="size-4 shrink-0" />
                                    <span>{{ match ($r['winner']) { 'us' => __('Point us'), 'them' => __('Point opponent'), default => __('No winner yet') } }}</span>
                                    <span class="font-normal text-muted">@if (! in_array($r['outcome'], [__('Point us'), __('Point opponent')], true))· {{ $r['outcome'] }} @endif· {{ $r['after'][0] }}:{{ $r['after'][1] }}</span>
                                </li>
                            </ol>

                            @if ($canCode)
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5 pl-2 text-xs">
                                    <button type="button" x-on:click="startAdd({{ $set['number'] }}, {{ $r['number'] }}, @js($last?->skill->value), @js($r['server']))"
                                            class="inline-flex items-center gap-1 rounded-lg bg-surface-2 px-2 py-1 font-semibold hover:bg-surface-3">
                                        <x-icon name="plus" class="size-3.5" /> {{ __('Add touch') }}
                                    </button>
                                    <span class="ml-1 text-muted">{{ __('Point') }}:</span>
                                    <button type="button" wire:click="setWinner({{ $set['number'] }}, {{ $r['number'] }}, 'us')" @class(['rounded-lg px-2 py-1 font-semibold', 'bg-success-500 text-white' => $r['winner'] === 'us', 'bg-success-500/15 text-success-400 hover:bg-success-500/25' => $r['winner'] !== 'us'])>{{ __('Us') }}</button>
                                    <button type="button" wire:click="setWinner({{ $set['number'] }}, {{ $r['number'] }}, 'them')" @class(['rounded-lg px-2 py-1 font-semibold', 'bg-danger-500 text-white' => $r['winner'] === 'them', 'bg-danger-500/15 text-danger-400 hover:bg-danger-500/25' => $r['winner'] !== 'them'])>{{ __('Opponent') }}</button>
                                    <button type="button" wire:click="setWinner({{ $set['number'] }}, {{ $r['number'] }}, null)" class="rounded-lg px-2 py-1 text-muted hover:bg-surface-2 hover:text-fg" title="{{ __('Use what the touches tell') }}">{{ __('Auto') }}</button>
                                    <span class="ml-1 text-muted">{{ __('Serve') }}:</span>
                                    <button type="button" wire:click="setServer({{ $set['number'] }}, {{ $r['number'] }}, 'us')" @class(['rounded-lg px-2 py-1 font-semibold', 'bg-brand-500 text-white' => $r['server'] === 'us', 'bg-surface-2 hover:bg-surface-3' => $r['server'] !== 'us'])>{{ __('Us') }}</button>
                                    <button type="button" wire:click="setServer({{ $set['number'] }}, {{ $r['number'] }}, 'them')" @class(['rounded-lg px-2 py-1 font-semibold', 'bg-brand-500 text-white' => $r['server'] === 'them', 'bg-surface-2 hover:bg-surface-3' => $r['server'] !== 'them'])>{{ __('Opponent') }}</button>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if ($canCode)
                <div x-show="! shut" class="px-3 pt-1">
                    <button type="button" wire:click="addRally({{ $set['number'] }})" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-brand-400 hover:bg-brand-500/10">
                        <x-icon name="plus" class="size-3.5" /> {{ __('Add rally to set :set', ['set' => $set['number']]) }}
                    </button>
                </div>
            @endif
        </section>
    @empty
        <p class="px-3 py-8 text-center text-sm text-muted">{{ __('No rallies yet. They appear as the AI or the coder records touches.') }}</p>
    @endforelse

    {{-- Touches without a set or rally: shown too, so nothing is missed --}}
    @if ($loose->isNotEmpty())
        <section x-data="{ shut: false }" class="pb-2">
            <header class="z-10 xl:sticky xl:top-0 flex items-center gap-2 border-y border-line bg-surface/95 px-3 py-2 backdrop-blur">
                <button type="button" x-on:click="shut = ! shut" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <x-icon name="chevron-down" class="size-4 shrink-0 text-muted transition" ::class="shut ? '-rotate-90' : ''" />
                    <span class="font-display text-base font-bold text-warning-400">{{ __('Not in a rally') }}</span>
                    <span class="text-xs tabular text-muted">{{ $loose->count() }}</span>
                </button>
            </header>
            <div x-show="! shut">
                <p class="px-3 py-1.5 text-xs text-muted">{{ __('These touches have no set or rally number. Edit them to put them in the right rally.') }}</p>
                <ol class="px-1">
                    @foreach ($loose as $a)
                        @include('livewire.games.partials.action-row', ['a' => $a])
                    @endforeach
                </ol>
            </div>
        </section>
    @endif

    {{-- Adding touches to a rally: pick player and skill, a grade records it at once (no save button) --}}
    @if ($canCode)
        <div x-show="form" x-cloak x-transition class="sticky bottom-0 z-20 border-t border-line bg-surface-2 p-3 shadow-2xl">
            <template x-if="form">
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-semibold">{{ __('Add touch') }}</span>
                        <span class="truncate text-xs text-muted" x-text="'Set ' + form.set + ' · #' + form.rally"></span>
                        <button type="button" x-on:click="form = null" class="ml-auto rounded-lg p-1 text-muted hover:text-fg" aria-label="{{ __('Close') }}"><x-icon name="x" class="size-4" /></button>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <select x-model="form.pid" class="input !px-2 !py-1 text-sm tabular" aria-label="{{ __('Player') }}">
                            <option value="">? {{ __('Unknown') }}</option>
                            <template x-for="p in roster" :key="p.id">
                                <option :value="String(p.id)" x-text="p.label" :selected="String(p.id) === form.pid"></option>
                            </template>
                        </select>
                        <select x-model="form.skill" class="input !px-2 !py-1 text-sm" aria-label="{{ __('Skill') }}">
                            <template x-for="s in skills" :key="s.value">
                                <option :value="s.value" x-text="s.label" :selected="s.value === form.skill"></option>
                            </template>
                        </select>
                    </div>
                    <div class="grid grid-cols-6 gap-1">
                        @foreach (\App\Enums\Grade::cases() as $g)
                            <button type="button" x-on:click="add(@js($g->value))" :disabled="busy" title="{{ $g->label() }}"
                                    class="rounded-lg py-1.5 font-display text-lg font-bold transition active:scale-95 disabled:opacity-40 {{ $gradeStyles[$g->value] }}">{{ $g->value }}</button>
                        @endforeach
                    </div>
                    <label class="flex items-center gap-1.5 text-xs text-muted">
                        <input type="checkbox" x-model="form.useTime" class="rounded">
                        <span>{{ __('Use the current video time') }}</span>
                    </label>
                    <p class="text-xs text-subtle">{{ __('The grade records the touch. The form stays open for the next one.') }}</p>
                </div>
            </template>
        </div>
    @endif
</div>
