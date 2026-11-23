<button type="button"
        x-data="{ light: document.documentElement.dataset.theme === 'light' }"
        x-on:click="
            light = ! light;
            const theme = light ? 'light' : 'dark';
            document.documentElement.dataset.theme = theme;
            try { localStorage.setItem('vva-theme', theme) } catch (e) {}
            @auth fetch(@js(route('preferences.update')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }, body: JSON.stringify({ theme }) }); @endauth
        "
        class="grid size-10 place-items-center rounded-xl text-muted transition hover:bg-surface-2 hover:text-fg"
        :aria-label="light ? @js(__('Switch to dark theme')) : @js(__('Switch to light theme'))">
    <x-icon name="moon" class="size-5" x-show="light" x-cloak />
    <x-icon name="sun" class="size-5" x-show="! light" />
</button>
