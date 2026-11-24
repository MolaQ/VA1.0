/**
 * "Point by point" tab of the match page: rallies of every set, expandable to every touch.
 *
 *  - click a touch / a rally time: jump the video there (the ytPlayer component around it)
 *  - the rally being played is highlighted; "follow" opens and scrolls to it as the video runs
 *  - coaches edit a touch (player, skill, grade, set / rally, time from the player)
 *    and add missed touches to a rally
 *
 * Roster, skills and grades come once as data, so the server does not send an edit form
 * for each of hundreds of touches.
 */
const NEXT_SKILL = { serve: 'reception', reception: 'set', set: 'attack', attack: 'dig', dig: 'set', block: 'dig', freeball: 'set' };

export default (roster = [], skills = [], grades = []) => ({
    roster,
    skills,
    grades,
    now: null,
    follow: false,
    followed: null,
    timer: null,
    form: null,
    busy: false,

    init() {
        this.timer = setInterval(() => this.tick(), 1000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    // The video player is the parent component (element marked data-yt).
    yt() {
        const el = this.$root.closest('[data-yt]');

        return el ? window.Alpine.$data(el) : null;
    },

    videoTime() {
        const t = this.yt()?.currentTime?.();

        return typeof t === 'number' && !Number.isNaN(t) ? t : null;
    },

    seek(t) {
        if (t !== null && t !== undefined) {
            this.yt()?.jump?.(t);
        }
    },

    tick() {
        this.now = this.videoTime();

        if (!this.follow || this.now === null) {
            return;
        }

        const row = [...this.$root.querySelectorAll('[data-rally]')].find((el) => this.isPlaying(el.dataset.start, el.dataset.end));

        if (row && row.dataset.rally !== this.followed) {
            this.followed = row.dataset.rally;
            window.dispatchEvent(new CustomEvent('vva-rally-follow', { detail: row.dataset.rally }));
            row.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        }
    },

    isPlaying(start, end) {
        const s = start === '' || start === null || start === undefined ? null : parseFloat(start);
        const e = end === '' || end === null || end === undefined ? s : parseFloat(end);

        return this.now !== null && s !== null && this.now >= s - 2 && this.now <= e + 4;
    },

    expandAll(open) {
        window.dispatchEvent(new CustomEvent('vva-rallies-expand', { detail: open }));
    },

    // One shared form (sticky at the bottom of the tab) for editing a touch or adding one.
    startEdit(a) {
        this.form = { mode: 'edit', ...a, pid: a.pid === null ? '' : String(a.pid), useTime: a.t === null };
    },

    startAdd(set, rally, lastSkill = null, server = null) {
        const skill = lastSkill ? NEXT_SKILL[lastSkill] ?? 'dig' : server === 'them' ? 'reception' : 'serve';
        this.form = { mode: 'add', set, rally, pid: '', skill, grade: '+', useTime: this.videoTime() !== null };
    },

    isEditing(id) {
        return this.form?.mode === 'edit' && this.form.id === id;
    },

    save() {
        const f = this.form;

        if (!f || this.busy) {
            return;
        }

        this.busy = true;
        const time = f.useTime ? this.videoTime() : null;
        const pid = f.pid ? parseInt(f.pid) : null;
        const call = f.mode === 'edit'
            ? this.$wire.updateAction(f.id, pid, f.skill, f.grade, f.set ? parseInt(f.set) : null, f.rally ? parseInt(f.rally) : null, time)
            : this.$wire.addAction(f.set, f.rally, pid, f.skill, f.grade, time);

        call.then(() => (this.form = null)).finally(() => (this.busy = false));
    },
});
