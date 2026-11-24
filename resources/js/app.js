/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Livewire components listen to
 * `echo-private:*` events, so match stats refresh live while the AI works.
 */
import './echo';
import './analytics';
import './pwa';
import './realtime';
import coder from './coder';
import court2d from './court2d';
import matchPanel from './match-panel';
import ralliesPanel from './rallies-panel';
import ytPlayer from './youtube-player';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ytPlayer', ytPlayer);
    window.Alpine.data('coder', coder);
    window.Alpine.data('court2d', court2d);
    window.Alpine.data('matchPanel', matchPanel);
    window.Alpine.data('ralliesPanel', ralliesPanel);
    // roster / skills / grades for editing touches in place (filled by the match page)
    window.Alpine.store('codes', { roster: [], skills: [] });
});
