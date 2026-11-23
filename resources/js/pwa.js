/**
 * Progressive Web App glue: registers the service worker and exposes an
 * Alpine store `$store.pwa` with `canInstall` / `install()` for install buttons.
 */
let deferredPrompt = null;

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('pwa', {
        canInstall: false,
        async install() {
            if (!deferredPrompt) {
                return;
            }

            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            this.canInstall = false;
        },
    });
});

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;

    if (window.Alpine?.store('pwa')) {
        window.Alpine.store('pwa').canInstall = true;
    }
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.Alpine?.store('pwa') && (window.Alpine.store('pwa').canInstall = false);
});
