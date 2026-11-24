<div class="w-full max-w-md animate-fade-up">
    <div class="card p-6 sm:p-8">
        <h1 class="font-display text-3xl font-bold">{{ __('Welcome back') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('Sign in to see match statistics and analyses.') }}</p>

        <form wire:submit="login" class="mt-8 space-y-5">
            <x-ui.field label="{{ __('Email') }}" for="email" error="email">
                <input id="email" type="email" wire:model="email" class="input" autocomplete="email" autofocus required>
            </x-ui.field>
            <x-ui.field label="{{ __('Password') }}" for="password" error="password">
                <input id="password" type="password" wire:model="password" class="input" autocomplete="current-password" required>
            </x-ui.field>
            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" wire:model="remember" class="size-4 rounded border-line-strong bg-surface-2 accent-brand-500"> {{ __('Remember me') }}
            </label>
            <x-ui.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
                <span wire:loading wire:target="login">{{ __('Signing in…') }}</span>
            </x-ui.button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm text-muted">
        {{ __('No club account yet?') }} <a href="{{ route('register') }}" wire:navigate class="font-semibold text-brand-400 hover:text-brand-300">{{ __('Create a club') }}</a>
    </p>
</div>
