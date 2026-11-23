<div class="w-full max-w-lg animate-fade-up">
    <div class="card p-6 sm:p-8">
        <h1 class="font-display text-3xl font-bold">{{ __('You don\'t belong to any club yet') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ __('Ask a club administrator for an invitation or create your own club.') }}</p>
        <form wire:submit="create" class="mt-6 space-y-4">
            <x-ui.field label="{{ __('New club name') }}" for="club_name" error="club_name">
                <input id="club_name" wire:model="club_name" class="input" required>
            </x-ui.field>
            <x-ui.button type="submit" class="w-full">{{ __('Create a club') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
            @csrf
            <button class="text-sm text-muted hover:text-fg">{{ __('Log out') }}</button>
        </form>
    </div>
</div>
