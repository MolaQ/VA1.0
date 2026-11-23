<div class="w-full max-w-xl animate-fade-up">
    <div class="card p-6 sm:p-8">
        <x-ui.badge tone="energy">{{ __('New club') }}</x-ui.badge>
        <h1 class="mt-3 font-display text-3xl font-bold">{{ __('Create a club account') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('You will become the administrator. You can invite coaches, players and parents later.') }}</p>

        <form wire:submit="register" class="mt-8 grid gap-5 sm:grid-cols-2">
            <x-ui.field label="{{ __('Club name') }}" for="club_name" error="club_name" class="sm:col-span-2">
                <input id="club_name" wire:model="club_name" class="input" placeholder="{{ __('e.g. Volley Club Kraków') }}" required>
            </x-ui.field>
            <x-ui.field label="{{ __('City') }}" for="city" error="city" class="sm:col-span-2">
                <input id="city" wire:model="city" class="input">
            </x-ui.field>
            <x-ui.field label="{{ __('Full name') }}" for="name" error="name">
                <input id="name" wire:model="name" class="input" autocomplete="name" required>
            </x-ui.field>
            <x-ui.field label="{{ __('Email') }}" for="email" error="email">
                <input id="email" type="email" wire:model="email" class="input" autocomplete="email" required>
            </x-ui.field>
            <x-ui.field label="{{ __('Password') }}" for="password" error="password">
                <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password" required>
            </x-ui.field>
            <x-ui.field label="{{ __('Confirm password') }}" for="password_confirmation">
                <input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password" required>
            </x-ui.field>
            <x-ui.button type="submit" size="lg" class="sm:col-span-2" wire:loading.attr="disabled">{{ __('Create club') }}</x-ui.button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm text-muted">
        {{ __('Already have an account?') }} <a href="{{ route('login') }}" wire:navigate class="font-semibold text-brand-400 hover:text-brand-300">{{ __('Sign in') }}</a>
    </p>
</div>
