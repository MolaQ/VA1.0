<div class="max-w-2xl">
    <x-ui.page-header eyebrow="{{ __('Club panel') }}" title="{{ __('Club settings') }}" />
    <x-ui.card>
        <form wire:submit="save" class="space-y-5">
            <x-ui.field label="{{ __('Club name') }}" for="c-name" error="name"><input id="c-name" wire:model="name" class="input" required></x-ui.field>
            <x-ui.field label="{{ __('City') }}" for="c-city" error="city"><input id="c-city" wire:model="city" class="input"></x-ui.field>
            <x-ui.field label="{{ __('Club colour') }}" for="c-color" error="accent_color" hint="{{ __('Used in reports and TV mode.') }}">
                <div class="flex items-center gap-3">
                    <input id="c-color" type="color" wire:model.live="accent_color" class="h-11 w-16 cursor-pointer rounded-xl border border-line-strong bg-surface-2 p-1">
                    <input wire:model.live="accent_color" class="input max-w-36 font-mono uppercase">
                    <span class="h-11 flex-1 rounded-xl" style="background: linear-gradient(90deg, {{ $accent_color }}, #22D3EE)"></span>
                </div>
            </x-ui.field>
            <div class="flex justify-end"><x-ui.button type="submit" icon="check">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</div>
