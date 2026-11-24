/**
 * Alpine component wrapping the YouTube IFrame API.
 *
 *  - seek(t)          jump to a second of the recording
 *  - load(clips)      play a list of moments ({ t, label }) one after another,
 *                     each from 3 s before to 4 s after the action
 *  - playStat(...)    asks Livewire for the clips behind a stat cell and plays them
 *  - jump(t)          jump to the moment of one action (1 s before it) and play
 *  - currentTime()    used by the manual coding panel to timestamp actions
 *
 * Links from the report open the match with ?t=<seconds> or ?clip=<player>,<skill>,<filter>,<zone>.
 */
let apiPromise = null;

function loadYouTubeApi() {
    if (window.YT?.Player) {
        return Promise.resolve(window.YT);
    }

    apiPromise ??= new Promise((resolve) => {
        const previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve(window.YT);
        };

        const script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        script.async = true;
        document.head.appendChild(script);
    });

    return apiPromise;
}

const CLIP_BEFORE = 3;
const CLIP_AFTER = 4;

export default (videoId) => ({
    player: null,
    ready: false,
    playlist: [],
    index: -1,
    clipEnd: null,
    emptyHint: false,
    timer: null,

    init() {
        if (!videoId || !this.$refs.frame) {
            return;
        }

        loadYouTubeApi().then((YT) => {
            this.player = new YT.Player(this.$refs.frame, {
                videoId,
                width: '100%',
                height: '100%',
                playerVars: { rel: 0, modestbranding: 1, playsinline: 1 },
                events: {
                    onReady: () => {
                        this.ready = true;
                        this.fromUrl();
                    },
                },
            });
        });

        this.timer = setInterval(() => this.tick(), 250);
    },

    destroy() {
        clearInterval(this.timer);
        this.player?.destroy?.();
    },

    currentTime() {
        return this.ready ? this.player.getCurrentTime() : null;
    },

    togglePlay() {
        if (!this.ready) {
            return;
        }

        // 1 = playing
        this.player.getPlayerState() === 1 ? this.player.pauseVideo() : this.player.playVideo();
    },

    pause() {
        if (this.ready) {
            this.player.pauseVideo();
        }
    },

    nudge(seconds) {
        if (this.ready) {
            this.player.seekTo(Math.max(0, this.player.getCurrentTime() + seconds), true);
        }
    },

    tick() {
        if (!this.ready || this.clipEnd === null) {
            return;
        }

        if (this.player.getCurrentTime() >= this.clipEnd) {
            this.next();
        }
    },

    seek(t, play = true) {
        if (!this.ready) {
            return;
        }

        this.player.seekTo(Math.max(0, t), true);

        if (play) {
            this.player.playVideo();
        }

        this.revealVideo();
    },

    // Bring the player into view when it is not visible (it is sticky on most layouts),
    // so clicking an action far down a list always shows the jump.
    revealVideo() {
        // not this.$el: called from a list button, Alpine's $el is that button (the old bug:
        // the page never scrolled back to the video, so a click seemed to do nothing)
        const el = document.querySelector('[data-yt] [x-ref="screen"]') ?? document.querySelector('[data-yt]');

        if (!el) {
            return;
        }

        const rect = el.getBoundingClientRect();
        const top = document.querySelector('body > div > header')?.offsetHeight ?? 0;

        if (rect.top < top - 1 || rect.bottom > window.innerHeight + 1) {
            el.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
        }
    },

    load(clips) {
        this.playlist = clips ?? [];
        this.index = -1;
        this.clipEnd = null;
        this.emptyHint = this.playlist.length === 0;

        if (this.emptyHint) {
            setTimeout(() => (this.emptyHint = false), 2500);

            return;
        }

        this.next();
    },

    playStat(playerId, skill, filter = 'all', zone = null) {
        this.$wire.clips(playerId, skill, filter, zone).then((clips) => this.load(clips));
    },

    jump(t) {
        this.stopPlaylist();
        this.seek(t - 1);
    },

    fromUrl() {
        const params = new URLSearchParams(window.location.search);
        const t = parseFloat(params.get('t'));

        if (!Number.isNaN(t)) {
            this.jump(t);

            return;
        }

        const clip = params.get('clip');

        if (clip) {
            const [player, skill, filter, zone] = clip.split(',');
            const num = (v) => (v && !Number.isNaN(parseInt(v)) ? parseInt(v) : null);
            this.playStat(num(player), skill, filter || 'all', num(zone));
        }
    },

    next() {
        if (this.index + 1 >= this.playlist.length) {
            this.clipEnd = null;
            this.player?.pauseVideo();

            return;
        }

        this.index++;
        this.playClip();
    },

    prev() {
        if (this.index > 0) {
            this.index--;
            this.playClip();
        }
    },

    playClip() {
        const clip = this.playlist[this.index];
        this.clipEnd = clip.t + CLIP_AFTER;
        this.seek(clip.t - CLIP_BEFORE);
    },

    stopPlaylist() {
        this.playlist = [];
        this.index = -1;
        this.clipEnd = null;
    },
});
