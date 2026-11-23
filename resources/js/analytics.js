/**
 * Cookie-less engagement beacon: reports how long the current page was
 * actually visible. Works with wire:navigate (one page view per visit).
 */
const state = { id: null, visibleMs: 0, since: null, sent: false };
const isPwa = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

function start() {
    flush();
    const el = document.getElementById('vva-view');
    state.id = el ? Number(el.dataset.id) : null;
    state.visibleMs = 0;
    state.sent = false;
    state.since = document.visibilityState === 'visible' ? performance.now() : null;
}

function pause() {
    if (state.since !== null) {
        state.visibleMs += performance.now() - state.since;
        state.since = null;
    }
}

function flush() {
    if (!state.id || state.sent) {
        return;
    }

    pause();
    state.sent = true;
    const body = JSON.stringify({ v: state.id, t: Math.round(state.visibleMs / 1000), pwa: isPwa() });
    navigator.sendBeacon?.('/a/e', new Blob([body], { type: 'application/json' }));
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        flush();
    } else if (state.sent) {
        // Came back to the tab: keep counting and send again on the next hide.
        state.sent = false;
        state.since = performance.now();
    } else {
        state.since = performance.now();
    }
});

window.addEventListener('pagehide', flush);
document.addEventListener('livewire:navigating', flush);
document.addEventListener('livewire:navigated', start);
document.addEventListener('DOMContentLoaded', start);
