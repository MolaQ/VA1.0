@php
    use App\Stats\PlayerStatLine;
    $fmtDuration = fn (int $s) => $s >= 60 ? intdiv($s, 60).' min '.($s % 60).' s' : $s.' s';
    $deviceLabels = ['mobile' => __('Phone'), 'tablet' => __('Tablet'), 'desktop' => __('Computer'), 'tv' => __('TV')];
    $languageLabels = \App\Support\Locales::supported();
@endphp
<div wire:poll.30s>
    <x-ui.page-header :eyebrow="__('Club panel')" :title="__('Analytics')" :subtitle="__('Cookie-less: no cookies, no IP addresses stored. Visitor ids rotate every day.')">
        <x-slot:actions>
            @if ($isSuperAdmin)
                <select wire:model.live="scope" class="input !w-auto">
                    <option value="club">{{ __('This club') }}</option>
                    <option value="platform">{{ __('Whole platform') }}</option>
                </select>
            @endif
            <div class="flex gap-1 rounded-xl bg-surface-2 p-1 text-sm">
                @foreach (['today' => __('Today'), '7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days')] as $key => $label)
                    <button wire:click="$set('period', '{{ $key }}')" @class(['rounded-lg px-3 py-1.5 font-semibold transition', 'bg-surface-3 text-fg shadow' => $period === $key, 'text-muted hover:text-fg' => $period !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 2xl:grid-cols-6">
        <div class="card relative overflow-hidden p-5 animate-fade-up">
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Online now') }}</p>
            <p class="mt-2 flex items-center gap-2 font-display text-3xl font-bold tabular">
                <span class="relative flex size-3"><span class="absolute inline-flex size-full animate-ping rounded-full bg-success-400 opacity-60"></span><span class="relative inline-flex size-3 rounded-full bg-success-500"></span></span>
                <span x-data x-text="Math.max({{ $activeNow }}, $store.online.members.length)">{{ $activeNow }}</span>
            </p>
            <p class="mt-1 text-xs text-subtle">{{ __('active in the last 5 min') }}</p>
        </div>
        <x-ui.stat :label="__('Unique visitors')" :value="$summary['visitors']" icon="users" />
        <x-ui.stat :label="__('Visits')" :value="$summary['visits']" :hint="$summary['views_per_visit'] !== null ? __(':n pages / visit', ['n' => $summary['views_per_visit']]) : null" icon="eye" tone="energy" />
        <x-ui.stat :label="__('Page views')" :value="$summary['pageviews']" icon="chart" />
        <x-ui.stat :label="__('Bounce rate')" :value="PlayerStatLine::percent($summary['bounce_rate'])" icon="arrow-left" tone="danger" />
        <x-ui.stat :label="__('Avg. visit duration')" :value="$fmtDuration($summary['avg_duration'])" :hint="__('Installed app: :p', ['p' => PlayerStatLine::percent($summary['pwa_share'])])" icon="clock" tone="success" />
    </div>

    <x-ui.card class="mt-6" :title="__('Traffic')" :subtitle="__('Page views and unique visitors')">
        <div class="flex h-56 items-end gap-1">
            @foreach ($series as $point)
                <div class="group relative flex h-full flex-1 flex-col justify-end" title="{{ $point['label'] }}: {{ $point['views'] }} / {{ $point['visitors'] }}">
                    <div class="pointer-events-none absolute -top-2 left-1/2 z-10 hidden -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-surface-3 px-2 py-1 text-xs shadow-lg group-hover:block">
                        <b>{{ $point['label'] }}</b> · {{ $point['views'] }} {{ __('views') }} · {{ $point['visitors'] }} {{ __('visitors') }}
                    </div>
                    <div class="relative w-full overflow-hidden rounded-t-md bg-brand-500/20 transition-all duration-700 group-hover:bg-brand-500/35" style="height: {{ max(1, $point['views'] / $maxViews * 100) }}%">
                        <div class="absolute inset-x-0 bottom-0 rounded-t-md bg-gradient-to-t from-brand-600 to-accent-400" style="height: {{ $point['views'] ? $point['visitors'] / $point['views'] * 100 : 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex justify-between text-[11px] text-subtle tabular">
            <span>{{ $series[0]['label'] ?? '' }}</span>
            <span>{{ $series[intdiv(count($series), 2)]['label'] ?? '' }}</span>
            <span>{{ end($series)['label'] ?? '' }}</span>
        </div>
        <div class="mt-3 flex gap-4 text-xs text-muted">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-500/30"></span>{{ __('Page views') }}</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-gradient-to-t from-brand-600 to-accent-400"></span>{{ __('Unique visitors') }}</span>
        </div>
    </x-ui.card>

    <div class="mt-6 grid gap-6 lg:grid-cols-2 2xl:grid-cols-3">
        <x-breakdown :title="__('Top pages')" :rows="$pages" />
        <x-breakdown :title="__('Sources')" :rows="$referrers" />
        <x-ui.card :title="__('Live')" :subtitle="__('Latest page views')" padding="p-3">
            @forelse ($feed as $row)
                <div class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm {{ $loop->first ? 'animate-flash' : '' }}">
                    <x-icon :name="match ($row['device']) { 'mobile', 'tablet' => 'phone', 'tv' => 'tv', default => 'monitor' }" class="size-4 text-muted" />
                    <span class="min-w-0 flex-1 truncate">{{ $row['path'] }}</span>
                    <span class="text-xs text-subtle">{{ $row['at'] }}</span>
                </div>
            @empty
                <p class="px-3 py-4 text-sm text-muted">{{ __('No data yet.') }}</p>
            @endforelse
        </x-ui.card>
        <x-breakdown :title="__('Devices')" :rows="$devices" :labels="$deviceLabels" />
        <x-breakdown :title="__('Browsers')" :rows="$browsers" />
        <x-breakdown :title="__('Operating systems')" :rows="$systems" />
        <x-breakdown :title="__('Countries')" :rows="$countries" />
        <x-breakdown :title="__('Languages')" :rows="$languages" :labels="$languageLabels" />
    </div>
</div>
