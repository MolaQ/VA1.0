@props(['name', 'title' => null, 'maxWidth' => 'max-w-lg'])
{{-- Open with $dispatch('open-modal', '{{ $name }}') / close with 'close-modal'. --}}
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === @js($name)) open = true"
     x-on:close-modal.window="if ($event.detail === @js($name) || ! $event.detail) open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak
     class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-6">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/60 backdrop-blur-sm" x-on:click="open = false"></div>
    <div x-show="open"
         x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-y-6 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-y-6"
         class="card relative w-full {{ $maxWidth }} max-h-[92vh] overflow-y-auto rounded-b-none sm:rounded-2xl">
        <header class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="font-display text-xl font-semibold">{{ $title }}</h2>
            <button type="button" class="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-fg" x-on:click="open = false" aria-label="{{ __('Close') }}">
                <x-icon name="x" class="size-5" />
            </button>
        </header>
        <div class="p-6">{{ $slot }}</div>
    </div>
</div>
