/**
 * Manual scouting panel: tap or type.
 *
 * Keyboard (when no input has focus):
 *   12        jersey number (digits typed quickly in a row)
 *   S R A B D E F   serve, reception, attack, block, dig, set, free ball
 *   1-9       after a skill: zone (attack: from, then to; serve: target)
 *   # + ! - / =     grade: records the touch
 *   ?         mark the next touch as unclear ("to clarify")
 *   P / O     point for us / for the opponent (closes the rally)
 *   N         next rally      Space  play / pause      ← →  −/+ 3 s      Esc  clear
 */
const SKILL_KEYS = { s: 'serve', r: 'reception', a: 'attack', b: 'block', d: 'dig', e: 'set', f: 'freeball' };
const GRADES = ['#', '+', '!', '-', '/', '='];

export default (roster = {}) => ({
    roster, // jersey number => player id
    player: null,
    skill: null,
    zoneFrom: null,
    zoneTo: null,
    newNumber: '',
    buffer: '',
    bufferTimer: null,
    keyboard: true,
    unclear: false,

    // The video player is the parent component (element marked data-yt).
    yt() {
        const el = this.$root.closest('[data-yt]');

        return el ? window.Alpine.$data(el) : null;
    },

    time() {
        return this.yt()?.currentTime() ?? null;
    },

    usesZones() {
        return this.skill === 'attack' || this.skill === 'serve';
    },

    pickSkill(skill) {
        this.skill = skill;
        this.zoneFrom = null;
        this.zoneTo = null;
    },

    pickZone(zone) {
        if (this.skill === 'serve') {
            this.zoneTo = zone;
        } else if (this.zoneFrom === null) {
            this.zoneFrom = zone;
        } else {
            this.zoneTo = zone;
        }
    },

    reset() {
        this.skill = null;
        this.zoneFrom = null;
        this.zoneTo = null;
    },

    grade(g) {
        if (!this.player || !this.skill) {
            return;
        }

        this.$wire.record(this.player, this.skill, g, this.time(), this.zoneFrom, this.zoneTo, this.unclear);
        this.unclear = false;
        this.reset();
    },

    point(winner) {
        this.$wire.point(winner, this.time());
        this.reset();
    },

    onKey(e) {
        if (!this.keyboard || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }

        const tag = e.target?.tagName;

        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(tag) || e.target?.isContentEditable) {
            return;
        }

        const key = e.key;

        if (key === '?') {
            e.preventDefault();
            this.unclear = !this.unclear;
        } else if (GRADES.includes(key)) {
            e.preventDefault();
            this.grade(key);
        } else if (/^[0-9]$/.test(key)) {
            e.preventDefault();

            if (this.skill && this.usesZones() && key !== '0') {
                this.pickZone(parseInt(key));

                return;
            }

            this.buffer = (this.buffer + key).slice(-2);
            clearTimeout(this.bufferTimer);
            this.bufferTimer = setTimeout(() => (this.buffer = ''), 900);

            const id = this.roster[parseInt(this.buffer)];

            if (id) {
                this.player = id;
                this.reset();
            }
        } else if (SKILL_KEYS[key.toLowerCase()] && this.player) {
            e.preventDefault();
            this.pickSkill(SKILL_KEYS[key.toLowerCase()]);
        } else if (key === 'p' || key === 'P') {
            e.preventDefault();
            this.point('us');
        } else if (key === 'o' || key === 'O') {
            e.preventDefault();
            this.point('them');
        } else if (key === 'n' || key === 'N') {
            e.preventDefault();
            this.$wire.nextRally();
        } else if (key === ' ') {
            e.preventDefault();
            this.yt()?.togglePlay();
        } else if (key === 'ArrowLeft' || key === 'ArrowRight') {
            e.preventDefault();
            this.yt()?.nudge(key === 'ArrowLeft' ? -3 : 3);
        } else if (key === 'Escape') {
            this.reset();
        }
    },
});
