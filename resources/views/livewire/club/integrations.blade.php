<div>
    <x-ui.page-header eyebrow="{{ __('Club panel') }}" title="{{ __('AI integrations') }}" subtitle="{{ __('The video-analysis module sends recognised actions through the API. Stats update live.') }}" />

    <div class="grid gap-6 xl:grid-cols-5">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="{{ __('Analyzer status') }}">
                <div class="flex items-center gap-3">
                    @php $ok = $analyzerConfigured || $agentOnline; @endphp
                    <span @class(['size-3 shrink-0 rounded-full', 'bg-success-500 animate-pulse-soft' => $ok, 'bg-warning-500' => ! $ok])></span>
                    <p class="text-sm">
                        @if ($agentOnline)
                            {{ __('Analysis agent is running.') }}
                        @elseif ($analyzerConfigured)
                            {{ __('AI analyzer connected (VVA_ANALYZER_URL).') }}
                        @elseif ($agentSeen)
                            {{ __('Analysis agent offline, last seen :time.', ['time' => $agentSeen->diffForHumans()]) }}
                        @else
                            {{ __('No analysis agent yet: install VVA Analyzer on a computer with a graphics card and paste a token from here.') }}
                        @endif
                    </p>
                </div>
                <p class="mt-3 text-xs text-muted">{{ __('Matches waiting or in analysis: :count', ['count' => $queued]) }}</p>
            </x-ui.card>

            <x-ui.card title="{{ __('Analyses') }}" subtitle="{{ __('Refreshes every few seconds.') }}" padding="p-0">
                <ul class="divide-y divide-line" wire:poll.5s.visible>
                    @forelse ($jobs as $job)
                        @php
                            $st = $job->analysis_status;
                            $left = $job->analysisSecondsLeft();
                        @endphp
                        <li class="px-5 py-3 text-sm" wire:key="job-{{ $job->id }}">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('games.show', $job) }}" wire:navigate class="min-w-0 flex-1 truncate font-semibold hover:text-brand-400">{{ $job->title() }}</a>
                                <span @class(['rounded-md px-2 py-0.5 text-xs font-semibold',
                                    'bg-brand-500/15 text-brand-400' => $st->value === 'processing',
                                    'bg-surface-3 text-muted' => $st->value === 'queued',
                                    'bg-success-500/15 text-success-400' => $st->value === 'completed',
                                    'bg-danger-500/15 text-danger-400' => $st->value === 'failed'])>{{ $st->label() }}</span>
                            </div>
                            @if ($st->value === 'processing')
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-3">
                                    <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ max(2, $job->analysis_progress) }}%"></div>
                                </div>
                            @endif
                            <p class="mt-1.5 text-xs text-muted">
                                @if ($st->value === 'processing'){{ $job->analysis_progress }}% · @endif
                                {{ $job->analysis_message ?? '—' }}
                            </p>
                            @if ($job->youtube_id === null)
                                <p class="mt-1 text-xs font-semibold text-warning-400">{{ __('No YouTube link: the agent cannot take this match.') }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-muted">
                                @if ($left !== null){{ __('About :time left', ['time' => \Carbon\CarbonInterval::seconds($left)->cascade()->forHumans(['short' => true, 'parts' => 2])]) }} · @endif
                                @if ($job->analysis_started_at && $st->value === 'processing'){{ __('started :time', ['time' => $job->analysis_started_at->diffForHumans()]) }} · @endif
                                {{ __('updated :time', ['time' => $job->analysis_updated_at?->diffForHumans() ?? '—']) }}
                            </p>
                            @if ($st->value !== 'queued')
                                <button type="button" wire:click="requeue({{ $job->id }})"
                                        @if ($st->value === 'processing') wire:confirm="{{ __('The analysis is still running or was interrupted. Put the match back in the queue?') }}" @endif
                                        class="mt-1.5 text-xs font-semibold text-brand-400 hover:underline">
                                    {{ $st->value === 'completed' ? __('Analyse again') : __('Back to the queue') }}
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-6 text-sm text-muted">{{ __('No analyses yet.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card title="{{ __('New API token') }}">
                <form wire:submit="createToken" class="space-y-4">
                    <x-ui.field label="{{ __('Name') }}" for="tokenName" error="tokenName"><input id="tokenName" wire:model="tokenName" class="input"></x-ui.field>
                    <x-ui.button type="submit" icon="key">{{ __('Create token') }}</x-ui.button>
                </form>
                @if ($plainToken)
                    <div class="mt-5 rounded-xl border border-success-500/30 bg-success-500/10 p-4" x-data="{ copied: false }">
                        <p class="text-sm font-semibold text-success-400">{{ __('Copy the token now: we won\'t show it again.') }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            {{-- an input, so the whole token can also be selected by hand (Ctrl+A, Ctrl+C) --}}
                            <input x-ref="token" readonly value="{{ $plainToken }}" x-on:focus="$el.select()"
                                   class="input min-w-0 flex-1 !py-2 font-mono text-xs">
                            <x-ui.button size="sm" variant="secondary" x-on:click="
                                const text = $refs.token.value;
                                const fallback = () => { $refs.token.focus(); $refs.token.select(); copied = document.execCommand('copy'); };
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).then(() => copied = true, fallback);
                                } else { fallback(); }
                            ">
                                <x-icon name="copy" class="size-4" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="{{ __('Active tokens') }}" padding="p-0">
                <ul class="divide-y divide-line">
                    @forelse ($tokens as $token)
                        <li class="flex items-center gap-3 px-5 py-3 text-sm">
                            <x-icon name="key" class="size-4 text-muted" />
                            <span class="min-w-0 flex-1"><span class="block truncate font-semibold">{{ $token->name }}</span><span class="text-xs text-muted">{{ __('Last used: :time', ['time' => $token->last_used_at?->diffForHumans() ?? __('never')]) }}</span></span>
                            <x-ui.button size="sm" variant="danger" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke token?') }}">{{ __('Revoke') }}</x-ui.button>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-sm text-muted">{{ __('No tokens.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card title="{{ __('API for the AI module') }}" subtitle="{{ __('Authorization') }}: Bearer <token>" class="xl:col-span-3">
            <div class="space-y-4 text-sm">
                @foreach ([
                    ['GET', '/games/{id}', __('Match metadata and roster (jersey numbers → player ids).')],
                    ['PATCH', '/games/{id}/analysis', __('Analysis status and progress: queued, processing (0–100), completed, failed.')],
                    ['POST', '/games/{id}/actions', __('Batch of actions (up to 1000). Idempotent by external_id.')],
                    ['PUT', '/games/{id}/sets/{nr}', __('Set score, where the set starts in the video and the lineup (rotation / substitutes).')],
                    ['DELETE', '/games/{id}/actions', __('Delete AI actions before re-analysis.')],
                ] as [$method, $path, $desc])
                    <div class="flex flex-wrap items-start gap-3 rounded-xl border border-line bg-surface-2 p-3">
                        <span class="w-16 rounded-md bg-brand-500/15 px-2 py-0.5 text-center font-mono text-xs font-bold text-brand-400">{{ $method }}</span>
                        <div class="min-w-0 flex-1"><code class="text-xs">{{ $apiBase }}{{ $path }}</code><p class="mt-1 text-muted">{{ $desc }}</p></div>
                    </div>
                @endforeach
                <pre class="overflow-x-auto rounded-xl bg-black/40 p-4 text-xs leading-relaxed text-accent-400">POST {{ $apiBase }}/games/42/actions
{
  "actions": [
    { "external_id": "det-000183", "set": 1, "rally": 14,
      "player_number": 13, "skill": "attack", "grade": "#",
      "video_time": 812.4, "zone_from": 4, "zone_to": 1,
      "confidence": 0.93 }
  ]
}</pre>
                <p class="text-muted">Skill: <code>serve, reception, attack, block, dig, set, freeball</code> · {{ __('Grade') }}: <code># + ! - / =</code></p>
            </div>
        </x-ui.card>
    </div>
</div>
