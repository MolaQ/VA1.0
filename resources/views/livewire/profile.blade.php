<div class="mx-auto max-w-4xl" x-on:theme-changed.window="
    let t = $event.detail.theme;
    try { localStorage.setItem('vva-theme', t) } catch (e) {}
    if (t === 'system') t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    document.documentElement.dataset.theme = t;
">
    <x-ui.page-header :eyebrow="__('Account')" :title="__('Profile and settings')" :subtitle="__('Your preferences are saved on your account and follow you to every device.')" />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Profile')">
            <form wire:submit="saveProfile" class="space-y-5">
                <x-ui.field :label="__('Full name')" for="p-name" error="name"><input id="p-name" wire:model="name" class="input" required></x-ui.field>
                <x-ui.field :label="__('Email')" for="p-email" error="email"><input id="p-email" type="email" wire:model="email" class="input" required></x-ui.field>
                <x-ui.field :label="__('Language')" for="p-locale" error="locale">
                    <select id="p-locale" wire:model="locale" class="input">
                        @foreach ($locales as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </select>
                </x-ui.field>
                <div>
                    <span class="label">{{ __('Theme') }}</span>
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-surface-2 p-1">
                        @foreach (['dark' => __('Dark'), 'light' => __('Light'), 'system' => __('System')] as $value => $label)
                            <button type="button" wire:click="$set('theme', '{{ $value }}')" @class(['rounded-lg py-2 text-sm font-semibold transition', 'bg-surface-3 text-fg shadow' => $theme === $value, 'text-muted hover:text-fg' => $theme !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex justify-end"><x-ui.button type="submit" icon="check">{{ __('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <div class="space-y-6">
            <x-ui.card :title="__('Mobile app')" :subtitle="__('Install VVA on your phone or tablet: it opens full screen and keeps you signed in.')">
                <div x-data="{ installed: window.matchMedia('(display-mode: standalone)').matches }">
                    <template x-if="installed">
                        <x-ui.badge tone="success">{{ __('Installed') }}</x-ui.badge>
                    </template>
                    <template x-if="! installed">
                        <div class="space-y-3">
                            <x-ui.button icon="arrow-right" x-show="$store.pwa?.canInstall" x-on:click="$store.pwa.install()">{{ __('Install app') }}</x-ui.button>
                            <p class="text-sm text-muted">{{ __('On iPhone: tap Share and then “Add to Home Screen”.') }}</p>
                        </div>
                    </template>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Change password')">
                <form wire:submit="updatePassword" class="space-y-4">
                    <x-ui.field :label="__('Current password')" for="p-cur" error="current_password"><input id="p-cur" type="password" wire:model="current_password" class="input" autocomplete="current-password"></x-ui.field>
                    <x-ui.field :label="__('New password')" for="p-new" error="password"><input id="p-new" type="password" wire:model="password" class="input" autocomplete="new-password"></x-ui.field>
                    <x-ui.field :label="__('Confirm password')" for="p-conf"><input id="p-conf" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password"></x-ui.field>
                    <div class="flex justify-end"><x-ui.button type="submit" variant="secondary">{{ __('Change password') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
