<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-dvh">
    <div class="relative flex min-h-dvh flex-col">
        <header class="flex items-center justify-between px-4 py-4 sm:px-8">
            <a href="{{ route('home') }}" wire:navigate><x-logo /></a>
            @include('partials.locale-switcher')
                @include('partials.theme-toggle')
        </header>
        <main class="flex flex-1 items-center justify-center px-4 pb-16 sm:px-8">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
