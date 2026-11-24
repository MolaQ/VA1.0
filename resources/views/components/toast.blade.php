{{-- Global toast: dispatch from Livewire with $this->dispatch('toast', message: '...', tone: 'success') --}}
<div x-data="{ items: [] }" x-init="@if (session('toast')) $nextTick(() => $dispatch('toast', { message: @js(session('toast')) })) @endif"
     x-on:toast.window="const id = Date.now() + Math.random(); items.push({ id, message: $event.detail.message ?? $event.detail[0]?.message, tone: $event.detail.tone ?? 'success', url: $event.detail.url ?? null }); setTimeout(() => items = items.filter(i => i.id !== id), 3800)"
     class="pointer-events-none fixed inset-x-0 bottom-20 z-[60] flex flex-col items-center gap-2 px-4 lg:bottom-6 lg:items-end lg:px-6">
    <template x-for="item in items" :key="item.id">
        <div x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0"
             x-on:click="if (item.url) Livewire.navigate(item.url)" :class="item.url ? 'cursor-pointer hover:border-line-strong' : ''"
             class="card pointer-events-auto flex max-w-sm items-center gap-3 px-4 py-3 text-sm font-medium">
            <span class="size-2 rounded-full" :class="{ 'bg-success-500': item.tone === 'success', 'bg-danger-500': item.tone === 'danger', 'bg-brand-500': item.tone === 'info' }"></span>
            <span x-text="item.message"></span>
        </div>
    </template>
</div>
