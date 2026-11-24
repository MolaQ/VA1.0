@php
    $status = $game->analysis_status;
    $won = $game->isWon();
    $gradeStyles = [
        '#' => 'bg-success-500 text-white', '+' => 'bg-success-500/20 text-success-400', '!' => 'bg-surface-3 text-fg',
        '-' => 'bg-warning-500/20 text-warning-400', '/' => 'bg-volley-500/20 text-volley-400', '=' => 'bg-danger-500 text-white',
    ];
@endphp
<div data-yt x-data="ytPlayer(@js($game->youtube_id))" x-on:vva-seek.window="seek($event.detail.t)">
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up">
        <div class="min-w-0">
            <a href="{{ route('games.index') }}" wire:navigate class="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-fg"><x-icon name="arrow-left" class="size-4" /> {{ __('Matches') }}</a>
            <h1 class="font-display text-3xl font-bold tracking-wide sm:text-4xl">
                {{ $game->team->name }} <span class="text-muted">vs</span> {{ $game->opponent }}
            </h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                <span class="inline-flex items-center gap-1"><x-icon name="calendar" class="size-4" /> {{ $game->played_at->translatedFormat('l, j F Y · H:i') }}</span>
                @if ($game->venue)<span>{{ $game->venue }}</span>@endif
                <span>{{ $game->is_home ? __('Home') : __('Away') }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="text-right">
                <p @class(['font-display text-5xl font-bold tabular leading-none', 'text-success-400' => $won === true, 'text-danger-400' => $won === false])>{{ $game->result() }}</p>
                <p class="mt-1 flex justify-end gap-1.5 text-xs text-muted tabular">
                    @foreach ($game->sets as $set)<span class="rounded bg-surface-3 px-1.5 py-0.5">{{ $set->score_us }}:{{ $set->score_them }}</span>@endforeach
                </p>
            </div>
            @can('report', $game)
                <x-ui.button size="sm" icon="chart" :href="route('games.report', $game)">{{ __('Report') }}</x-ui.button>
            @endcan
            @if ($canEdit)
                <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('games.edit', $game)">{{ __('Edit') }}</x-ui.button>
            @endif
        </div>
    </div>

    {{-- Analysis status --}}
    @if ($status !== \App\Enums\AnalysisStatus::Completed && $game->youtube_id)
        <div class="card mb-6 flex flex-wrap items-center gap-4 p-4 animate-fade-up">
            <span @class(['grid size-10 place-items-center rounded-xl', 'bg-accent-500/15 text-accent-400' => $status->isActive(), 'bg-danger-500/15 text-danger-400' => $status === \App\Enums\AnalysisStatus::Failed, 'bg-surface-3 text-muted' => ! $status->isActive() && $status !== \App\Enums\AnalysisStatus::Failed])>
                <x-icon name="sparkles" @class(['size-5', 'animate-pulse-soft' => $status->isActive()]) />
            </span>
            <div class="min-w-48 flex-1">
                <p class="text-sm font-semibold">{{ $status->label() }} @if ($status->isActive())<span class="tabular text-muted">· {{ $game->analysis_progress }}%</span>@endif</p>
                <p class="text-xs text-muted">{{ $game->analysis_message ?? __('Stats appear live as the AI recognises new actions.') }}</p>
                @if ($status->isActive())
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-accent-400 transition-all duration-700" style="width: {{ $game->analysis_progress }}%"></div></div>
                @endif
            </div>
            @if ($canEdit && ! $status->isActive())
                <x-ui.button size="sm" variant="secondary" icon="sparkles" wire:click="reanalyze">{{ __('Run AI analysis') }}</x-ui.button>
            @endif
        </div>
    @endif

    {{--
        Layout: the video stays in view while the analyst browses actions.
        Phones / tablets / narrow laptops: the player sticks under the top bar and the panel scrolls below it.
        Wide screens (xl+): video + court on the left, the panel on the right sticks to the viewport
        and scrolls inside, so browsing the timeline never scrolls the page (or the video) away.
    --}}
    <div class="grid gap-4 xl:grid-cols-12 xl:grid-rows-[auto_1fr] xl:gap-6">
        {{-- Video --}}
        <div x-ref="screen" class="sticky top-16 z-20 -mx-4 scroll-mt-16 sm:-mx-6 xl:static xl:col-span-7 xl:row-start-1 xl:mx-0 2xl:col-span-8">
            <div class="card overflow-hidden rounded-none border-x-0 bg-black shadow-xl sm:rounded-2xl sm:border-x xl:shadow-none">
                <div class="relative mx-auto aspect-video w-full max-w-[calc(40dvh*16/9)] bg-black xl:max-w-none" wire:ignore>
                    @if ($game->youtube_id)
                        <div x-ref="frame" class="size-full"></div>
                    @else
                        <div class="grid size-full place-items-center text-center text-sm text-subtle">
                            <div><x-icon name="video" class="mx-auto mb-2 size-10" />{{ __('No recording. Add a YouTube link in the match settings.') }}</div>
                        </div>
                    @endif
                </div>
                {{-- Clip playlist bar --}}
                <div x-show="playlist.length" x-cloak x-transition class="flex flex-wrap items-center gap-3 border-t border-line bg-surface-2 px-4 py-3">
                    <x-ui.badge tone="accent" pulse>{{ __('Clip') }} <span x-text="index + 1"></span>/<span x-text="playlist.length"></span></x-ui.badge>
                    <span class="min-w-0 flex-1 truncate text-sm font-medium" x-text="playlist[index]?.label"></span>
                    <div class="flex items-center gap-1">
                        <x-ui.button size="sm" variant="ghost" x-on:click="prev()" aria-label="{{ __('Previous') }}"><x-icon name="arrow-left" class="size-4" /></x-ui.button>
                        <x-ui.button size="sm" variant="ghost" x-on:click="next()" aria-label="{{ __('Next') }}"><x-icon name="arrow-right" class="size-4" /></x-ui.button>
                        <x-ui.button size="sm" variant="ghost" x-on:click="stopPlaylist()" aria-label="{{ __('Close') }}"><x-icon name="x" class="size-4" /></x-ui.button>
                    </div>
                </div>
                <div x-show="emptyHint" x-cloak x-transition class="border-t border-line px-4 py-3 text-sm text-muted">{{ __('No video moments for this stat.') }}</div>
            </div>
        </div>

        {{-- Side panel: timeline, quick pick, coding, review queue as tabs --}}
        @php
            $tabs = ['timeline' => __('Timeline')];
            if ($game->youtube_id) { $tabs['pick'] = __('Quick pick'); }
            if ($canCode) { $tabs['coding'] = __('Coding'); }
            if ($canCode && $reviewCount > 0) { $tabs['review'] = __('To clarify'); }
        @endphp
        <section x-data="matchPanel(@js(array_keys($tabs)))" class="card flex min-h-0 flex-col overflow-hidden animate-fade-up xl:sticky xl:top-20 xl:col-span-5 xl:col-start-8 xl:row-span-2 xl:row-start-1 xl:h-[calc(100dvh-6rem)] 2xl:col-span-4 2xl:col-start-9">
            <nav class="flex shrink-0 flex-wrap gap-1 border-b border-line p-1.5" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" x-on:click="show(@js($key))" :aria-selected="tab === @js($key)"
                            :class="tab === @js($key) ? 'bg-surface-3 text-fg shadow' : 'text-muted hover:text-fg'"
                            class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-sm font-semibold transition">
                        {{ $label }}
                        @if ($key === 'timeline')<span class="text-xs tabular opacity-60">{{ $timeline->count() }}</span>@endif
                        @if ($key === 'review')<span class="rounded-full bg-warning-500/20 px-1.5 text-xs tabular text-warning-400">{{ $reviewCount }}</span>@endif
                    </button>
                @endforeach
            </nav>
            <div class="xl:min-h-0 xl:flex-1 xl:overflow-y-auto xl:overscroll-contain">
                <div x-show="tab === 'timeline'" class="p-2">
                    <p class="px-3 pb-1 pt-1 text-xs text-muted">{{ __('Click to jump in the video.') }}</p>
                <ul class="divide-y divide-line">
                    @forelse ($timeline as $a)
                        <li wire:key="a-{{ $a->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2 transition hover:bg-surface-2 {{ $loop->first ? 'animate-flash' : '' }}">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @if ($a->video_time !== null) x-on:click="jump({{ $a->video_time }})" @endif>
                                <span class="w-12 shrink-0 text-xs tabular text-muted">{{ \App\Support\YouTube::formatTime($a->video_time) }}</span>
                                <span class="grid size-7 shrink-0 place-items-center rounded-lg text-sm font-bold {{ $gradeStyles[$a->grade->value] }}">{{ $a->grade->value }}</span>
                                <span class="flex min-w-0 items-center gap-1.5 text-sm"><x-jersey :player="$a->player" class="overflow-hidden" /> <span class="shrink-0 text-muted">·</span> <span class="shrink-0">{{ $a->skill->label() }}</span>@if ($a->zone_from || $a->zone_to)<span class="text-xs text-subtle">{{ $a->zone_from }}{{ $a->zone_from && $a->zone_to ? '→' : '' }}{{ $a->zone_to }}</span>@endif</span>
                            </button>
                            @if ($a->needs_review)
                                <span class="text-warning-400" title="{{ __('To clarify') }}: {{ $a->reviewReason() }}"><x-icon name="flag" class="size-4" /></span>
                            @endif
                            @if ($a->source === \App\Enums\ActionSource::Ai)
                                <x-ui.badge tone="accent" class="max-sm:hidden" title="{{ __('Confidence') }} {{ $a->confidence !== null ? round($a->confidence * 100).'%' : '–' }}">AI</x-ui.badge>
                            @endif
                            @if ($a->set)<span class="text-xs text-subtle">S{{ $a->set->number }}</span>@endif
                            @if ($canCode && ! $a->needs_review)
                                <button type="button" wire:click="flagAction({{ $a->id }})" class="rounded-lg p-1 text-subtle opacity-0 transition hover:text-warning-400 group-hover:opacity-100" title="{{ __('Leave to clarify') }}" aria-label="{{ __('Leave to clarify') }}"><x-icon name="flag" class="size-4" /></button>
                            @endif
                            @if ($canCode)
                                <button type="button" wire:click="deleteAction({{ $a->id }})" wire:confirm="{{ __('Delete this action?') }}" class="rounded-lg p-1 text-subtle opacity-0 transition hover:text-danger-400 group-hover:opacity-100" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
                            @endif
                        </li>
                    @empty
                        <li class="px-3 py-8 text-center text-sm text-muted">{{ __('No recorded actions.') }}</li>
                    @endforelse
                </ul>
                </div>

                @if ($game->youtube_id)
                    <div x-show="tab === 'pick'" x-cloak>
                    @php $pickPlayers = $stats->lines->filter(fn ($l) => $l->totalActions() > 0)->map(fn ($l) => $l->player)->filter()->values(); @endphp
                    <div x-data="{ pid: null, skill: 'attack', filter: 'all', zone: null }" class="no-print space-y-2 p-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="label !mb-0 mr-1">{{ __('Quick pick') }}</span>
                            <button type="button" x-on:click="pid = null" :class="pid === null ? 'bg-brand-500 text-white' : 'bg-surface-2 hover:bg-surface-3'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ __('Team') }}</button>
                            @foreach ($pickPlayers as $pl)
                                <button type="button" x-on:click="pid = {{ $pl->id }}" title="{{ $pl->full_name }}" :class="pid === {{ $pl->id }} ? 'bg-brand-500 text-white' : 'bg-surface-2 hover:bg-surface-3'" class="min-w-8 rounded-lg px-1.5 py-1 font-display text-sm font-bold tabular">{{ $pl->number ?? '?' }}</button>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach ([\App\Enums\Skill::Serve, \App\Enums\Skill::Reception, \App\Enums\Skill::Attack, \App\Enums\Skill::Block, \App\Enums\Skill::Dig, \App\Enums\Skill::Set] as $sk)
                                <button type="button" x-on:click="skill = @js($sk->value); zone = null" :class="skill === @js($sk->value) ? 'bg-accent-500 text-white' : 'bg-surface-2 hover:bg-surface-3'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ $sk->label() }}</button>
                            @endforeach
                            <span class="mx-1 h-4 w-px bg-line-strong"></span>
                            @foreach (['all' => __('All'), '#' => '#', 'pos' => '# +', '=' => '='] as $f => $label)
                                <button type="button" x-on:click="filter = @js($f)" :class="filter === @js($f) ? 'bg-surface-3 text-fg ring-1 ring-line-strong' : 'text-muted hover:text-fg'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ $label }}</button>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5" x-show="skill === 'attack' || skill === 'serve'">
                            <span class="text-xs text-muted" x-text="skill === 'attack' ? @js(__('Attack from zone')) : @js(__('Serve to zone'))"></span>
                            <button type="button" x-on:click="zone = null" :class="zone === null ? 'bg-surface-3 text-fg ring-1 ring-line-strong' : 'text-muted hover:text-fg'" class="rounded-lg px-2 py-1 text-xs font-semibold">{{ __('All') }}</button>
                            @foreach ([4, 3, 2, 5, 6, 1, 7, 8, 9] as $z)
                                <button type="button" x-on:click="zone = {{ $z }}" :class="zone === {{ $z }} ? 'bg-surface-3 text-fg ring-1 ring-line-strong' : 'text-muted hover:text-fg'" class="min-w-6 rounded-lg px-1.5 py-1 text-xs font-bold tabular">{{ $z }}</button>
                            @endforeach
                        </div>
                        <x-ui.button size="sm" icon="play" x-on:click="playStat(pid, skill, filter, zone)">{{ __('Play these actions') }}</x-ui.button>
                    </div>
                    </div>
                @endif

                @if ($canCode)
                    {{-- x-show (not x-if): the keyboard shortcuts keep working while another tab is open --}}
                    <div x-show="tab === 'coding'" x-cloak class="p-4">
                        <p class="mb-3 text-xs text-muted">{{ __('Correct or complete the AI analysis. Time is taken from the player.') }}</p>
                    @php $numbers = $roster->whereNotNull('number')->mapWithKeys(fn ($p) => [$p->number => $p->id]); @endphp
                    <div x-data="coder(@js((object) $numbers->all()))" x-on:keydown.window="onKey($event)" class="space-y-4">
                        <div class="flex items-center gap-2 text-sm">
                            <label class="text-muted">Set</label>
                            <select wire:model.live="codingSet" class="input !w-auto !py-1.5">
                                @for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                            </select>
                            <span class="ml-auto text-muted">{{ __('Rally') }} <b class="tabular text-fg">{{ $rally }}</b></span>
                            <x-ui.button size="sm" variant="secondary" wire:click="nextRally" title="N">+1</x-ui.button>
                        </div>
                        <div>
                            <p class="label">{{ __('1. Player') }}</p>
                            <div class="grid grid-cols-6 gap-1.5">
                                @foreach ($roster as $p)
                                    <button type="button" x-on:click="player = {{ $p->id }}" title="{{ $p->full_name }}"
                                            :class="player === {{ $p->id }} ? 'bg-brand-500 text-white ring-2 ring-brand-400/50' : 'bg-surface-2 hover:bg-surface-3'"
                                            class="rounded-xl py-2 font-display text-lg font-bold transition active:scale-95">{{ $p->number ?? '–' }}</button>
                                @endforeach
                            </div>
                            <form class="mt-2 flex gap-1.5" x-on:submit.prevent="if (newNumber !== '') { $wire.addNumber(parseInt(newNumber)).then(id => { if (id) { player = id; roster[parseInt(newNumber)] = id } newNumber = '' }) }">
                                <input type="number" min="0" max="99" x-model="newNumber" class="input !py-1.5 text-sm" placeholder="{{ __('Another number, e.g. 47') }}" aria-label="{{ __('Another number, e.g. 47') }}">
                                <x-ui.button type="submit" size="sm" variant="secondary" icon="plus">{{ __('Add') }}</x-ui.button>
                            </form>
                        </div>
                        <div>
                            <p class="label">{{ __('2. Skill') }}</p>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach ($skills as $s)
                                    <button type="button" x-on:click="pickSkill(@js($s->value))" :disabled="! player" title="{{ $s->code() }}"
                                            :class="skill === @js($s->value) ? 'bg-brand-500 text-white' : 'bg-surface-2 hover:bg-surface-3'"
                                            class="rounded-xl py-2 text-sm font-semibold transition active:scale-95 disabled:opacity-40">{{ $s->label() }} <span class="text-[10px] opacity-60">{{ $s->code() }}</span></button>
                                @endforeach
                            </div>
                        </div>
                        <div x-show="usesZones()" x-cloak x-transition>
                            <p class="label">
                                <span x-show="skill === 'attack'">{{ __('Zone (optional): from, then to') }}</span>
                                <span x-show="skill === 'serve'">{{ __('Serve target zone (optional)') }}</span>
                            </p>
                            {{-- opponent court seen from our side: 4 3 2 / 7 8 9 / 5 6 1 --}}
                            <div class="grid grid-cols-3 gap-1">
                                @foreach ([4, 3, 2, 7, 8, 9, 5, 6, 1] as $z)
                                    <button type="button" x-on:click="pickZone({{ $z }})"
                                            :class="zoneFrom === {{ $z }} ? 'bg-accent-500 text-white' : (zoneTo === {{ $z }} ? 'bg-brand-500 text-white' : 'bg-surface-2 hover:bg-surface-3')"
                                            class="rounded-lg py-1.5 text-sm font-bold tabular transition">{{ $z }}</button>
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-muted" x-show="zoneFrom || zoneTo"><span x-show="zoneFrom">{{ __('From') }} <b x-text="zoneFrom"></b></span> <span x-show="zoneTo">→ <b x-text="zoneTo"></b></span></p>
                        </div>
                        <div>
                            <p class="label">{{ __('3. Grade') }}</p>
                            <div class="grid grid-cols-6 gap-1.5">
                                @foreach ($grades as $g)
                                    <button type="button" :disabled="! player || ! skill" title="{{ $g->label() }}"
                                            x-on:click="grade(@js($g->value))"
                                            class="rounded-xl py-2.5 font-display text-xl font-bold transition active:scale-95 disabled:opacity-30 {{ $gradeStyles[$g->value] }}">{{ $g->value }}</button>
                                @endforeach
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-muted">
                            <input type="checkbox" x-model="unclear" class="rounded">
                            <span>{{ __('Unclear, leave to clarify') }} <span class="text-[10px] opacity-60">?</span></span>
                        </label>
                        <div>
                            <p class="label">{{ __('Rally point (opponent error, opponent kill)') }}</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button type="button" x-on:click="point('us')" class="rounded-xl bg-success-500/15 py-2 text-sm font-semibold text-success-400 transition hover:bg-success-500/25 active:scale-95">{{ __('Point us') }} <span class="text-[10px] opacity-60">P</span></button>
                                <button type="button" x-on:click="point('them')" class="rounded-xl bg-danger-500/15 py-2 text-sm font-semibold text-danger-400 transition hover:bg-danger-500/25 active:scale-95">{{ __('Point opponent') }} <span class="text-[10px] opacity-60">O</span></button>
                            </div>
                        </div>
                        <details class="rounded-xl bg-surface-2 px-3 py-2 text-xs text-muted">
                            <summary class="cursor-pointer font-semibold text-fg">{{ __('Keyboard shortcuts') }}</summary>
                            <ul class="mt-2 space-y-1">
                                <li><kbd>13</kbd> {{ __('jersey number') }} · <kbd>S R A B D E F</kbd> {{ __('skill') }} · <kbd>1–9</kbd> {{ __('zone') }}</li>
                                <li><kbd># + ! - / =</kbd> {{ __('grade, records the touch') }} · <kbd>?</kbd> {{ __('unclear') }}</li>
                                <li><kbd>P</kbd> / <kbd>O</kbd> {{ __('point us / opponent') }} · <kbd>N</kbd> {{ __('next rally') }}</li>
                                <li><kbd>{{ __('Space') }}</kbd> {{ __('play / pause') }} · <kbd>← →</kbd> ±3 s · <kbd>Esc</kbd> {{ __('clear') }}</li>
                            </ul>
                        </details>
                    </div>
                    </div>
                @endif

                @if ($canCode && $reviewCount > 0)
                    <div x-show="tab === 'review'" x-cloak class="p-2">
                        <p class="px-3 pb-1 pt-1 text-xs text-muted">{{ __('The AI was not sure about these. Watch, pick the player or grade, confirm.') }}</p>
                    <ul class="divide-y divide-line">
                        @foreach ($review as $a)
                            <li wire:key="rv-{{ $a->id }}" x-data="{ pid: @js($a->player_id), grade: @js($a->grade->value) }" class="space-y-2 rounded-xl px-3 py-2.5">
                                <div class="flex items-center gap-2">
                                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm" @if ($a->video_time !== null) x-on:click="jump({{ $a->video_time }})" title="{{ __('Jump to this moment') }}" @else disabled title="{{ __('No video time') }}" @endif>
                                        <x-icon :name="$a->video_time !== null ? 'play' : 'clock'" @class(['size-4 shrink-0', 'text-brand-400' => $a->video_time !== null, 'text-subtle' => $a->video_time === null]) />
                                        <span class="w-11 shrink-0 text-xs tabular text-muted">{{ \App\Support\YouTube::formatTime($a->video_time) }}</span>
                                        <x-jersey :player="$a->player" />
                                        <span class="truncate">{{ $a->skill->label() }} {{ $a->grade->value }}</span>
                                    </button>
                                    @if ($a->set)<span class="text-xs text-subtle">S{{ $a->set->number }}</span>@endif
                                </div>
                                <p class="text-xs text-warning-400">{{ $a->reviewReason() }}</p>
                                @if ($a->video_time === null)
                                    <p class="text-xs text-muted">{{ __('No video time: find this moment in the video, then confirm and the current video time is saved with it.') }}</p>
                                @endif
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <select x-model.number="pid" class="input !w-auto !px-2 !py-1 text-sm tabular" aria-label="{{ __('Player') }}">
                                        <option value="">?</option>
                                        @foreach ($roster as $pl)
                                            <option value="{{ $pl->id }}">{{ $pl->numberLabel() }}{{ $pl->isAnonymous() ? '' : ' '.$pl->short_name }}</option>
                                        @endforeach
                                    </select>
                                    <select x-model="grade" class="input !w-auto !px-2 !py-1 text-sm" aria-label="{{ __('Grade') }}">
                                        @foreach ($grades as $g)<option value="{{ $g->value }}">{{ $g->value }}</option>@endforeach
                                    </select>
                                    <x-ui.button size="sm" icon="check" x-on:click="$wire.resolveAction({{ $a->id }}, pid || null, grade{{ $a->video_time === null ? ', currentTime()' : '' }})">{{ __('Confirm') }}</x-ui.button>
                                    <button type="button" wire:click="deleteAction({{ $a->id }})" wire:confirm="{{ __('Delete this action?') }}" class="rounded-lg p-1 text-subtle hover:text-danger-400" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    </div>
                @endif
            </div>
        </section>

        @if ($game->youtube_id)
            <div class="card overflow-hidden xl:col-span-7 xl:row-start-2 2xl:col-span-8">
                @include('livewire.games.partials.court-2d')
            </div>
        @endif
    </div>


    {{-- Box score --}}
    <x-ui.card class="mt-6" title="{{ __('Player stats') }}" subtitle="{{ __('Click a number to watch those actions on video.') }}" padding="p-0 sm:p-0">
        <x-slot:actions>
            <div class="flex gap-1 rounded-xl bg-surface-2 p-1 text-sm">
                <button wire:click="$set('setNumber', null)" @class(['rounded-lg px-3 py-1.5 font-semibold transition', 'bg-surface-3 text-fg shadow' => $setNumber === null, 'text-muted hover:text-fg' => $setNumber !== null])>{{ __('Match') }}</button>
                @foreach ($game->sets as $set)
                    <button wire:click="$set('setNumber', {{ $set->number }})" @class(['rounded-lg px-3 py-1.5 font-semibold transition', 'bg-surface-3 text-fg shadow' => $setNumber === $set->number, 'text-muted hover:text-fg' => $setNumber !== $set->number])>S{{ $set->number }}</button>
                @endforeach
            </div>
        </x-slot:actions>
        <div class="p-3 sm:p-4" wire:loading.class="opacity-60" wire:target="setNumber">
            @include('livewire.games.partials.stats-table')
        </div>
    </x-ui.card>

    @if ($canEdit)
        <x-ui.card class="mt-6" title="{{ __('Set scores') }}" subtitle="{{ __('Filled in automatically by the AI, you can correct them.') }}">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                @for ($i = 1; $i <= 5; $i++)
                    @php $set = $game->sets->firstWhere('number', $i); @endphp
                    <div x-data="{ us: {{ $set->score_us ?? 0 }}, them: {{ $set->score_them ?? 0 }} }" class="rounded-xl border border-line bg-surface-2 p-3">
                        <p class="label">Set {{ $i }}</p>
                        <div class="flex items-center gap-1.5">
                            <input type="number" min="0" max="99" x-model.number="us" class="input !px-2 !py-1.5 text-center tabular">
                            <span class="text-muted">:</span>
                            <input type="number" min="0" max="99" x-model.number="them" class="input !px-2 !py-1.5 text-center tabular">
                        </div>
                        <button type="button" x-on:click="$wire.saveSetScore({{ $i }}, us, them)" class="mt-2 w-full rounded-lg py-1 text-xs font-semibold text-brand-400 hover:bg-brand-500/10">{{ __('Save') }}</button>
                    </div>
                @endfor
            </div>
        </x-ui.card>

        <x-ui.card class="mt-6" title="{{ __('Starting lineups') }}" subtitle="{{ __('Rotation at the start of each set: position I serves first. With it the AI knows who serves each rally.') }}">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @for ($i = 1; $i <= 5; $i++)
                    @php
                        $set = $game->sets->firstWhere('number', $i);
                        $byPos = $set ? $set->lineups->whereNotNull('rotation_position')->pluck('player_id', 'rotation_position')->all() : [];
                        $first = $set?->we_serve_first === null ? '' : ($set->we_serve_first ? 'us' : 'them');
                    @endphp
                    <div x-data="{ pos: @js((object) $byPos), first: @js($first) }" wire:key="lineup-{{ $i }}" class="rounded-xl border border-line bg-surface-2 p-3">
                        <p class="label">Set {{ $i }}</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            {{-- court layout as seen from behind our end line: IV III II / V VI I --}}
                            @foreach ([4, 3, 2, 5, 6, 1] as $p)
                                <label class="block">
                                    <span class="text-[10px] font-semibold uppercase text-muted">{{ ['', 'I', 'II', 'III', 'IV', 'V', 'VI'][$p] }}</span>
                                    <select x-model.number="pos[{{ $p }}]" class="input !px-1.5 !py-1 text-sm tabular">
                                        <option value="">–</option>
                                        @foreach ($roster as $pl)
                                            <option value="{{ $pl->id }}">{{ $pl->numberLabel() }}{{ $pl->isAnonymous() ? '' : ' '.$pl->short_name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                        <label class="mt-2 block">
                            <span class="text-[10px] font-semibold uppercase text-muted">{{ __('First serve') }}</span>
                            <select x-model="first" class="input !px-1.5 !py-1 text-sm">
                                <option value="">{{ __('AI decides') }}</option>
                                <option value="us">{{ __('Us') }}</option>
                                <option value="them">{{ __('Opponent') }}</option>
                            </select>
                        </label>
                        <button type="button" x-on:click="$wire.saveLineup({{ $i }}, pos, first || null)" class="mt-2 w-full rounded-lg py-1 text-xs font-semibold text-brand-400 hover:bg-brand-500/10">{{ __('Save') }}</button>
                    </div>
                @endfor
            </div>
        </x-ui.card>
    @endif
</div>
