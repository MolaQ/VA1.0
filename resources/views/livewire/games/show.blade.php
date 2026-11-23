@php
    $status = $game->analysis_status;
    $won = $game->isWon();
    $gradeStyles = [
        '#' => 'bg-success-500 text-white', '+' => 'bg-success-500/20 text-success-400', '!' => 'bg-surface-3 text-fg',
        '-' => 'bg-warning-500/20 text-warning-400', '/' => 'bg-volley-500/20 text-volley-400', '=' => 'bg-danger-500 text-white',
    ];
@endphp
<div x-data="ytPlayer(@js($game->youtube_id))" x-on:vva-seek.window="seek($event.detail.t)">
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
        <div class="flex items-center gap-3">
            <div class="text-right">
                <p @class(['font-display text-5xl font-bold tabular leading-none', 'text-success-400' => $won === true, 'text-danger-400' => $won === false])>{{ $game->result() }}</p>
                <p class="mt-1 flex justify-end gap-1.5 text-xs text-muted tabular">
                    @foreach ($game->sets as $set)<span class="rounded bg-surface-3 px-1.5 py-0.5">{{ $set->score_us }}:{{ $set->score_them }}</span>@endforeach
                </p>
            </div>
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

    <div class="grid gap-6 xl:grid-cols-12">
        {{-- Video --}}
        <div class="xl:col-span-8">
            <div class="card overflow-hidden xl:sticky xl:top-20">
                <div class="relative aspect-video bg-black" wire:ignore>
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

        {{-- Side panel: coding (staff) + timeline --}}
        <div class="space-y-6 xl:col-span-4">
            @if ($canCode)
                <x-ui.card title="{{ __('Manual coding') }}" subtitle="{{ __('Correct or complete the AI analysis. Time is taken from the player.') }}" padding="p-4">
                    <div x-data="{ player: null, skill: null }" class="space-y-4">
                        <div class="flex items-center gap-2 text-sm">
                            <label class="text-muted">Set</label>
                            <select wire:model.live="codingSet" class="input !w-auto !py-1.5">
                                @for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                            </select>
                            <span class="ml-auto text-muted">{{ __('Rally') }} <b class="tabular text-fg">{{ $rally }}</b></span>
                            <x-ui.button size="sm" variant="secondary" wire:click="nextRally">+1</x-ui.button>
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
                        </div>
                        <div>
                            <p class="label">{{ __('2. Skill') }}</p>
                            <div class="grid grid-cols-3 gap-1.5">
                                @foreach ($skills as $s)
                                    <button type="button" x-on:click="skill = @js($s->value)" :disabled="! player"
                                            :class="skill === @js($s->value) ? 'bg-brand-500 text-white' : 'bg-surface-2 hover:bg-surface-3'"
                                            class="rounded-xl py-2 text-sm font-semibold transition active:scale-95 disabled:opacity-40">{{ $s->label() }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <p class="label">{{ __('3. Grade') }}</p>
                            <div class="grid grid-cols-6 gap-1.5">
                                @foreach ($grades as $g)
                                    <button type="button" :disabled="! player || ! skill" title="{{ $g->label() }}"
                                            x-on:click="$wire.record(player, skill, @js($g->value), currentTime()); skill = null"
                                            class="rounded-xl py-2.5 font-display text-xl font-bold transition active:scale-95 disabled:opacity-30 {{ $gradeStyles[$g->value] }}">{{ $g->value }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="{{ __('Timeline') }}" subtitle="{{ __('Click to jump in the video.') }}" padding="p-2">
                <ul class="max-h-[28rem] divide-y divide-line overflow-y-auto">
                    @forelse ($timeline as $a)
                        <li wire:key="a-{{ $a->id }}" class="group flex items-center gap-3 rounded-xl px-3 py-2 transition hover:bg-surface-2 {{ $loop->first ? 'animate-flash' : '' }}">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @if ($a->video_time !== null) x-on:click="seek({{ $a->video_time }} - 2)" @endif>
                                <span class="w-12 shrink-0 text-xs tabular text-muted">{{ \App\Support\YouTube::formatTime($a->video_time) }}</span>
                                <span class="grid size-7 shrink-0 place-items-center rounded-lg text-sm font-bold {{ $gradeStyles[$a->grade->value] }}">{{ $a->grade->value }}</span>
                                <span class="min-w-0 truncate text-sm"><b>#{{ $a->player?->number ?? '?' }}</b> {{ $a->player?->last_name }} · {{ $a->skill->label() }}</span>
                            </button>
                            @if ($a->source === \App\Enums\ActionSource::Ai)
                                <x-ui.badge tone="accent" title="{{ __('Confidence') }} {{ $a->confidence !== null ? round($a->confidence * 100).'%' : '–' }}">AI</x-ui.badge>
                            @endif
                            @if ($a->set)<span class="text-xs text-subtle">S{{ $a->set->number }}</span>@endif
                            @if ($canCode)
                                <button type="button" wire:click="deleteAction({{ $a->id }})" wire:confirm="{{ __('Delete this action?') }}" class="rounded-lg p-1 text-subtle opacity-0 transition hover:text-danger-400 group-hover:opacity-100" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="size-4" /></button>
                            @endif
                        </li>
                    @empty
                        <li class="px-3 py-8 text-center text-sm text-muted">{{ __('No recorded actions.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
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
    @endif
</div>
