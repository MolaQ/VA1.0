@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $club = $user?->currentClub;
    $isStaff = $user?->isStaffOf($club);
    $isAdmin = $user?->isAdminOf($club);
    $isParentOnly = $club && $user && ! $isStaff && $user->rolesIn($club)->every(fn ($r) => $r === \App\Enums\Role::Parent);

    $nav = array_values(array_filter([
        ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'home', 'label' => __('Dashboard')],
        ['route' => 'games.index', 'match' => 'games.*', 'icon' => 'video', 'label' => __('Matches')],
        ['route' => 'players.index', 'match' => 'players.*', 'icon' => 'users', 'label' => $isParentOnly ? __('My children') : __('Players')],
    ]));

    $adminNav = $isAdmin ? [
        ['route' => 'club.members', 'match' => 'club.members', 'icon' => 'shield', 'label' => __('Members & roles')],
        ['route' => 'club.teams', 'match' => 'club.teams', 'icon' => 'volleyball', 'label' => __('Teams')],
        ['route' => 'club.integrations', 'match' => 'club.integrations', 'icon' => 'key', 'label' => __('AI integrations')],
        ['route' => 'club.settings', 'match' => 'club.settings', 'icon' => 'settings', 'label' => __('Club settings')],
    ] : [];

    $platformNav = $user?->is_super_admin ? [
        ['route' => 'platform.clubs', 'match' => 'platform.*', 'icon' => 'building', 'label' => __('Clubs (platform)')],
    ] : [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh" x-data="{ drawer: false }">
    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-line bg-bg-elevated/80 backdrop-blur-xl lg:flex">
        <div class="flex h-16 items-center px-5">
            <a href="{{ route('dashboard') }}" wire:navigate><x-logo /></a>
        </div>
        @include('partials.sidebar-nav')
    </aside>

    {{-- Mobile drawer --}}
    <div x-show="drawer" x-cloak class="fixed inset-0 z-50 lg:hidden">
        <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-black/60 backdrop-blur-sm" x-on:click="drawer = false"></div>
        <aside x-show="drawer" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="relative flex h-full w-72 flex-col border-r border-line bg-bg-elevated">
            <div class="flex h-16 items-center justify-between px-5">
                <x-logo />
                <button class="rounded-lg p-1.5 text-muted hover:text-fg" x-on:click="drawer = false" aria-label="{{ __('Close menu') }}"><x-icon name="x" /></button>
            </div>
            @include('partials.sidebar-nav')
        </aside>
    </div>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="glass sticky top-0 z-30 flex h-16 items-center gap-3 border-b px-4 sm:px-6">
            <button class="grid size-10 place-items-center rounded-xl text-muted hover:bg-surface-2 hover:text-fg lg:hidden" x-on:click="drawer = true" aria-label="Menu">
                <x-icon name="menu" />
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate class="lg:hidden"><x-logo compact /></a>

            <div class="ml-auto flex items-center gap-1 sm:gap-2">
                @if ($user && $user->clubCount() > 1)
                    <livewire:club-switcher />
                @elseif ($club)
                    <span class="hidden items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold sm:inline-flex">
                        <span class="grid size-7 place-items-center rounded-lg bg-surface-3 text-[11px] font-bold">{{ $club->initials() }}</span>
                        {{ $club->name }}
                    </span>
                @endif
                @include('partials.locale-switcher')
                @include('partials.theme-toggle')
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button class="flex items-center gap-2 rounded-xl p-1.5 pr-2 transition hover:bg-surface-2" x-on:click="open = ! open">
                        <span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-brand-500 to-accent-500 text-xs font-bold text-white">{{ $user?->initials() }}</span>
                        <span class="hidden text-left text-sm leading-tight md:block">
                            <span class="block font-semibold">{{ $user?->name }}</span>
                            <span class="block text-xs text-muted">{{ $user?->is_super_admin ? 'Super admin' : $user?->primaryRole()?->label() }}</span>
                        </span>
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top.right class="card absolute right-0 mt-2 w-56 p-1.5">
                        <div class="border-b border-line px-3 py-2 text-xs text-muted">{{ $user?->email }}</div>
                        <a href="{{ route('profile') }}" wire:navigate class="nav-link"><x-icon name="settings" class="size-4" /> {{ __('Profile and settings') }}</a>
                        <button type="button" x-show="$store.pwa?.canInstall" x-cloak x-on:click="$store.pwa.install()" class="nav-link w-full"><x-icon name="arrow-right" class="size-4" /> {{ __('Install app') }}</button>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="nav-link w-full" type="submit"><x-icon name="logout" class="size-4" /> {{ __('Log out') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1600px] px-4 pb-28 pt-6 sm:px-6 lg:px-8 lg:pb-12">
            {{ $slot }}
        </main>
    </div>

    {{-- Mobile bottom tab bar --}}
    <nav class="glass fixed inset-x-0 bottom-0 z-30 border-t pb-[env(safe-area-inset-bottom)] lg:hidden">
        <div class="mx-auto grid max-w-md grid-cols-4">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" wire:navigate
                   class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold transition {{ request()->routeIs($item['match']) ? 'text-brand-400' : 'text-muted hover:text-fg' }}">
                    <x-icon :name="$item['icon']" class="size-5" />
                    {{ $item['label'] }}
                </a>
            @endforeach
            <button class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold text-muted hover:text-fg" x-on:click="drawer = true">
                <x-icon name="menu" class="size-5" /> {{ __('More') }}
            </button>
        </div>
    </nav>

    <x-toast />
    @livewireScripts
</body>
</html>
