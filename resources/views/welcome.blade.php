<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    @include('partials.head', ['title' => __('Volleyball analysis from video')])
</head>
<body class="min-h-dvh overflow-x-hidden">
    <header class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-8">
        <x-logo />
        <nav class="flex items-center gap-2">
            @include('partials.locale-switcher')
                @include('partials.theme-toggle')
            @auth
                <x-ui.button :href="route('dashboard')" size="sm">{{ __('Go to the app') }}</x-ui.button>
            @else
                <x-ui.button :href="route('login')" variant="ghost" size="sm">{{ __('Sign in') }}</x-ui.button>
                <x-ui.button :href="route('register')" size="sm">{{ __('Create a club') }}</x-ui.button>
            @endauth
        </nav>
    </header>

    <main class="mx-auto max-w-7xl px-4 sm:px-8">
        <section class="grid items-center gap-12 py-12 lg:grid-cols-2 lg:py-24">
            <div class="animate-fade-up">
                <x-ui.badge tone="accent" pulse>{{ __('AI analysis of YouTube recordings') }}</x-ui.badge>
                <h1 class="mt-5 font-display text-5xl font-bold leading-[1.02] tracking-wide sm:text-6xl xl:text-7xl">
                    {{ __('Every rally.') }}<br><span class="text-gradient">{{ __('Every player.') }}</span><br>{{ __('Straight from video.') }}
                </h1>
                <p class="mt-6 max-w-xl text-lg text-muted">
                    {{ __('Paste a match link and VVA recognises serves, receptions, attacks and blocks, computes the stats and lets you watch each of them with one click. For coaches, players and parents.') }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-ui.button :href="route('register')" size="lg" icon="arrow-right">{{ __('Start for free') }}</x-ui.button>
                    <x-ui.button :href="route('login')" size="lg" variant="secondary">{{ __('I have an account') }}</x-ui.button>
                </div>
            </div>

            <div class="relative animate-fade-up" style="animation-delay: 120ms">
                <div class="absolute -inset-10 rounded-full bg-gradient-to-tr from-brand-500/25 via-accent-400/10 to-volley-500/20 blur-3xl"></div>
                <div class="card relative overflow-hidden">
                    <div class="flex aspect-video items-center justify-center bg-gradient-to-br from-surface-3 to-bg">
                        <span class="grid size-16 place-items-center rounded-full bg-white/10 text-white backdrop-blur"><x-icon name="play" class="size-7" /></span>
                    </div>
                    <div class="grid grid-cols-4 gap-px bg-line text-center">
                        @foreach ([[__('Points'), '20', 'text-volley-400'], ['Kill%', '70%', 'text-success-400'], [__('Rec. pos.'), '62%', 'text-fg'], [__('Aces'), '2', 'text-accent-400']] as [$l, $v, $c])
                            <div class="bg-surface p-4"><p class="text-[11px] font-semibold tracking-wide text-muted uppercase">{{ $l }}</p><p class="mt-1 font-display text-2xl font-bold {{ $c }}">{{ $v }}</p></div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 pb-24 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['sparkles', __('AI analysis'), __('Actions are recognised in the recording and added live.')],
                ['play', __('Stat = video'), __('Click a number in the table and watch exactly those actions.')],
                ['users', __('Roles & privacy'), __('Admin, coach, player, parent: everyone sees what they should.')],
                ['tv', __('From phone to TV'), __('Readable on court, in the locker room and on the big screen.')],
            ] as [$icon, $t, $d])
                <div class="card p-6 transition hover:-translate-y-1 hover:border-line-strong animate-fade-up" style="animation-delay: {{ $loop->index * 80 }}ms">
                    <span class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-brand-500/20 to-accent-400/10 text-brand-400"><x-icon :name="$icon" /></span>
                    <h3 class="mt-4 font-display text-xl font-semibold">{{ $t }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ $d }}</p>
                </div>
            @endforeach
        </section>
    </main>
    @livewireScripts
</body>
</html>
