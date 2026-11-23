/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Livewire components listen to
 * `echo-private:*` events, so match stats refresh live while the AI works.
 */
import './echo';
import './analytics';
import './pwa';
import './realtime';
import ytPlayer from './youtube-player';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ytPlayer', ytPlayer);
});
