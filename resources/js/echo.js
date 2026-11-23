import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    // Host, port and scheme fall back to the page address, so a production build works
    // on any domain or LAN IP (Caddy proxies /app/* to Reverb). Local dev sets them in .env.
    const scheme = import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
    const port = import.meta.env.VITE_REVERB_PORT || window.location.port || (scheme === 'https' ? 443 : 80);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
