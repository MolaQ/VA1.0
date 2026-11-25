/**
 * 2D court under the video: players as dots (jersey number + a small triangle for the
 * direction the body faces), following the current video time.
 *
 * Lives inside the ytPlayer component and reads its clock (currentTime()), so seeking
 * the video moves the dots. Two modes:
 *  - rotation (default): our six players in their zones for the rally being played, from the
 *    starting lineup, the side-outs and the liberos (server: RotationTimeline). Players glide
 *    to their new zones when the rotation changes.
 *  - live (beta): positions from the analyzer (frames every ~0.5 s, interpolated by track).
 *    The analyst only corrects the jersey number of a track.
 * Dots are kept per player / track and moved with a CSS transition, so they move smoothly.
 *
 * Coordinates are court metres as the camera sees the court: x 0..9 across, y 0..18 along
 * (0 = near end line, 9 = net). On screen the court lies horizontally like the zone chart:
 * screen x = court y (near half on the left), screen y = court x.
 * Facing f: degrees, 0 = towards the far end line, 90 = towards x = 9 (clockwise from above),
 * which is exactly an SVG rotate(f) on screen.
 */
const SVG = 'http://www.w3.org/2000/svg';
const CHUNK = 60; // seconds of positions fetched at once
const AI_GAP = 1.6; // AI frames further apart than this are not interpolated

const round2 = (v) => Math.round(v * 100) / 100;

// Base spot of each zone in a team's own frame (x as the team faces the net, y 0 = own end line).
const ZONES = { 1: [7.5, 2.8], 2: [7.5, 7.3], 3: [4.5, 7.3], 4: [1.5, 7.3], 5: [1.5, 2.8], 6: [4.5, 2.8] };
const SERVER_SPOT = [7.5, -1.1]; // behind the end line

const storage = {
    get(key) { try { return window.localStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { window.localStorage.setItem(key, value); } catch { /* private mode */ } },
};

export default (config) => ({
    sets: config.sets ?? [],
    canCode: !!config.canCode,
    frames: [],
    labels: {},
    chunks: {},
    t: null,
    shownT: null,
    shownSrc: null,
    dots: [],
    mode: storage.get('vva-court-mode') === 'live' ? 'live' : 'rotation',
    rot: { players: {}, rallies: [] },
    current: null, // rally entry shown in rotation mode
    els: new Map(),
    selected: null,
    ball: { open: false, actions: [], action: null, step: null, suggested: null, zone: null },
    timer: null,

    init() {
        this.timer = setInterval(() => this.tick(), 150);
        this.loadRotations();
        this.refresh = () => this.loadRotations();
        window.addEventListener('stats-refreshed', this.refresh);
        this.$nextTick(() => this.render());
    },

    loadRotations() {
        this.$wire.rotations().then((res) => {
            this.rot = res ?? { players: {}, rallies: [] };
        }).catch(() => {});
    },

    setMode(mode) {
        this.mode = mode;
        this.selected = null;
        storage.set('vva-court-mode', mode);
        this.els.forEach((g) => g.remove());
        this.els.clear();
        this.tick();
    },

    destroy() {
        clearInterval(this.timer);
        window.removeEventListener('stats-refreshed', this.refresh);
    },

    // ------------------------------------------------------------ time & data

    tick() {
        const t = this.currentTime?.();

        if (t === null || t === undefined) {
            return;
        }

        this.t = t;

        if (this.mode === 'rotation') {
            this.dots = this.rotationAt(t);
            this.render();

            return;
        }

        this.ensure(t);

        if (!this.selected) {
            this.dots = this.positionsAt(t);
            this.render();
        }
    },

    /** Rally being played at t: the last one that started before it (or starts within 1 s). */
    rallyAt(t) {
        let found = null;
        for (const r of this.rot.rallies ?? []) {
            if (r.t <= t + 1) found = r;
            else break;
        }

        return found;
    },

    rotationAt(t) {
        const r = this.rallyAt(t);
        this.current = r;
        if (!r) return [];

        const side = this.sideOfSet(r.set);
        const facing = side === 'near' ? 0 : 180;

        return Object.entries(r.zones ?? {}).map(([zone, id]) => {
            const player = this.rot.players?.[id] ?? {};
            const serving = Number(zone) === 1 && r.serve === 'us';
            const [x, y] = serving ? SERVER_SPOT : ZONES[zone];
            const c = this.teamToCamera(x, y, side);

            return {
                key: `p${id}`, team: 'us', n: player.n ?? null, libero: !!player.libero, zone: Number(zone),
                serving, x: c.x, y: c.y, f: facing,
            };
        });
    },

    sideOfSet(number) {
        return this.sets.find((s) => s.number === number)?.side ?? this.ourSide(this.t ?? 0);
    },

    teamToCamera(x, y, side) {
        return side === 'near' ? { x, y } : { x: 9 - x, y: 18 - y };
    },

    rotInfo() {
        const r = this.current;
        if (!r) return '';
        const zone1 = this.rot.players?.[r.zones?.[1]]?.n;

        return { set: r.set, rot: r.rot, us: r.us, them: r.them, serve: r.serve, zone1: zone1 ?? '?' };
    },

    ensure(t) {
        const c = Math.floor(t / CHUNK);
        [c, c + 1].forEach((i) => {
            if (i < 0 || this.chunks[i]) {
                return;
            }

            this.chunks[i] = 'loading';
            this.$wire.frames(i * CHUNK, (i + 1) * CHUNK - 0.01).then((res) => {
                this.chunks[i] = 'done';
                this.labels = { ...this.labels, ...(res.labels ?? {}) };
                this.merge(res.frames ?? []);
            }).catch(() => delete this.chunks[i]);
        });
    },

    merge(list) {
        const key = (f) => `${f.src}:${f.t.toFixed(2)}`;
        const map = new Map(this.frames.map((f) => [key(f), f]));
        list.forEach((f) => map.set(key(f), { ...f, t: Number(f.t) }));
        this.frames = [...map.values()].sort((a, b) => a.t - b.t);
    },

    setAt(t) {
        let current = null;
        [...this.sets].filter((s) => s.start !== null).sort((a, b) => a.start - b.start)
            .forEach((s) => { if (s.start <= t + 1) current = s; });

        return current ?? this.sets.find((s) => s.number === Number(this.$wire.codingSet)) ?? null;
    },

    ourSide(t) {
        return this.setAt(t)?.side ?? 'near';
    },

    toggleSide() {
        const set = this.setAt(this.t ?? 0);
        const number = set?.number ?? Number(this.$wire.codingSet) ?? 1;
        const side = this.ourSide(this.t ?? 0) === 'near' ? 'far' : 'near';
        this.$wire.setOurSide(number, side).then(() => {
            const existing = this.sets.find((s) => s.number === number);
            existing ? (existing.side = side) : this.sets.push({ number, start: null, side });
            this.render();
        });
    },

    positionsAt(t) {
        const ai = this.frames;
        let prev = null;
        let next = null;
        for (const f of ai) {
            if (f.t <= t) prev = f;
            else { next = f; break; }
        }

        if (!prev || t - prev.t > AI_GAP) {
            if (next && next.t - t <= 0.3) {
                prev = next;
            } else {
                this.shownT = null;
                this.shownSrc = null;

                return [];
            }
        }

        this.shownT = prev.t;
        this.shownSrc = 'ai';
        const k = next && next.t - prev.t <= AI_GAP && next.t > prev.t ? (t - prev.t) / (next.t - prev.t) : 0;
        const after = new Map((next?.p ?? []).filter((p) => p.k !== null && p.k !== undefined).map((p) => [p.k, p]));

        return prev.p.map((p) => {
            const q = k > 0 && p.k !== null && p.k !== undefined ? after.get(p.k) : null;
            if (!q) return this.decorate(p);

            return this.decorate({ ...p, x: p.x + (q.x - p.x) * k, y: p.y + (q.y - p.y) * k, f: q.f ?? p.f });
        });
    },

    decorate(p) {
        const label = p.k !== null && p.k !== undefined ? this.labels[p.k] : null;

        // the analyst's correction wins over the AI number (also when it clears the number)
        return {
            ...p,
            n: label ? (label.n ?? null) : (p.n ?? null),
            team: label?.team ?? p.team ?? 'us',
        };
    },

    // ------------------------------------------------------------ geometry

    toSvg(evt) {
        const svg = this.$refs.court;
        const pt = svg.createSVGPoint();
        pt.x = evt.clientX;
        pt.y = evt.clientY;
        const p = pt.matrixTransform(svg.getScreenCTM().inverse());

        // screen -> court
        return { x: p.y, y: p.x };
    },

    /** camera court frame <-> our team's frame (x as we face the net, y 0 = our end line) */
    toTeam(x, y, t) {
        return this.ourSide(t) === 'near' ? { x, y } : { x: 9 - x, y: 18 - y };
    },

    fromTeam(x, y, t) {
        return this.toTeam(x, y, t);
    },

    // ------------------------------------------------------------ rendering

    render() {
        const layer = this.$refs.dots;
        if (!layer) return;

        const seen = new Set();
        this.dots.forEach((p, i) => {
            const key = p.key ?? (p.k !== null && p.k !== undefined ? `k${p.k}` : `i${i}`);
            seen.add(key);
            let g = this.els.get(key);
            if (!g) {
                g = this.dot(p);
                g.style.transform = `translate(${p.y}px, ${p.x}px)`;
                layer.appendChild(g);
                this.els.set(key, g);
            }
            this.update(g, p, i);
        });

        this.els.forEach((g, key) => {
            if (!seen.has(key)) {
                g.remove();
                this.els.delete(key);
            }
        });

        this.renderBall();
    },

    dot(p) {
        const g = document.createElementNS(SVG, 'g');
        g.classList.add('court-dot', this.mode === 'rotation' ? 'court-dot-glide' : 'court-dot-live');

        const facing = document.createElementNS(SVG, 'g');
        facing.classList.add('court-dot-turn');
        const tri = document.createElementNS(SVG, 'polygon');
        tri.setAttribute('points', '0.42,-0.17 0.7,0 0.42,0.17');
        tri.classList.add('court-dot-facing');
        facing.appendChild(tri);
        g.appendChild(facing);

        const c = document.createElementNS(SVG, 'circle');
        c.setAttribute('r', '0.38');
        c.classList.add('court-dot-body');
        g.appendChild(c);

        const text = document.createElementNS(SVG, 'text');
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'central');
        text.setAttribute('font-size', '0.38');
        text.classList.add('court-dot-label');
        g.appendChild(text);

        return g;
    },

    update(g, p, i) {
        g.dataset.index = i;
        g.style.transform = `translate(${p.y}px, ${p.x}px)`;
        g.classList.toggle('court-dot-us', p.team !== 'them');
        g.classList.toggle('court-dot-them', p.team === 'them');
        g.classList.toggle('court-dot-libero', !!p.libero);
        g.classList.toggle('court-dot-serving', !!p.serving);
        g.classList.toggle('court-dot-unknown', p.n === null || p.n === undefined);
        g.classList.toggle('cursor-pointer', this.mode === 'live' && this.canCode && p.k !== null && p.k !== undefined);
        g.classList.toggle('court-dot-selected', !!(this.selected && this.selected.k === p.k));

        const turn = g.querySelector('.court-dot-turn');
        turn.style.display = p.f === null || p.f === undefined ? 'none' : '';
        if (p.f !== null && p.f !== undefined) {
            // turn the shortest way round
            const prev = Number(turn.dataset.f ?? p.f);
            const next = prev + ((((p.f - prev) % 360) + 540) % 360) - 180;
            turn.dataset.f = next;
            turn.style.transform = `rotate(${next}deg)`;
        }

        const text = g.querySelector('text');
        const label = p.n ?? '?';
        if (text.textContent !== String(label)) text.textContent = label;
    },

    renderBall() {
        const layer = this.$refs.ball;
        if (!layer) return;
        layer.replaceChildren();

        const a = this.ball.action;
        if (!this.ball.open || !a) return;

        const t = a.t ?? this.t ?? 0;
        const points = [['from', a.from], ['to', a.to]].filter(([, v]) => v);
        const screen = points.map(([w, v]) => {
            const c = this.fromTeam(v[0], v[1], t);

            return [w, c.y, c.x];
        });

        if (screen.length === 2) {
            const line = document.createElementNS(SVG, 'line');
            line.setAttribute('x1', screen[0][1]); line.setAttribute('y1', screen[0][2]);
            line.setAttribute('x2', screen[1][1]); line.setAttribute('y2', screen[1][2]);
            line.classList.add('court-ball-path');
            layer.appendChild(line);
        }

        screen.forEach(([w, sx, sy]) => {
            const c = document.createElementNS(SVG, 'circle');
            c.setAttribute('cx', sx); c.setAttribute('cy', sy); c.setAttribute('r', w === 'to' ? '0.25' : '0.18');
            c.classList.add('court-ball');
            layer.appendChild(c);
        });

        // pass target of the receiving team, with the 1 m circle (reception "#")
        if (a.skill === 'reception') {
            const c = this.fromTeam(6, 8, t);
            const ring = document.createElementNS(SVG, 'circle');
            ring.setAttribute('cx', c.y); ring.setAttribute('cy', c.x); ring.setAttribute('r', '1');
            ring.classList.add('court-target');
            layer.appendChild(ring);
        }
    },

    // ------------------------------------------------------------ number correction

    /**
     * Positions are automatic. The analyst only fixes who a dot is when the AI lost the
     * jersey number or is unsure: the number (and team) goes to the whole analyzer track.
     */
    onPointerDown(evt) {
        if (this.ball.open && this.ball.step) {
            this.placeBall(evt);

            return;
        }

        const g = evt.target.closest?.('.court-dot');
        if (!this.canCode || !g || this.mode !== 'live') {
            this.selected = null;
            this.render();

            return;
        }

        const dot = this.dots[Number(g.dataset.index)];
        if (!dot || dot.k === null || dot.k === undefined) return;

        this.pause?.();
        this.selected = { k: dot.k, n: dot.n ?? '', team: dot.team };
        this.render();
    },

    saveLabel() {
        const sel = this.selected;
        if (!sel) return;
        const parsed = parseInt(sel.n);
        const n = Number.isNaN(parsed) ? null : Math.max(0, Math.min(99, parsed));

        this.$wire.labelTrack(sel.k, n, sel.team).then(() => {
            this.labels = { ...this.labels, [sel.k]: { team: sel.team, n } };
            this.selected = null;
            this.dots = this.positionsAt(this.t ?? 0);
            this.render();
        });
    },

    // ------------------------------------------------------------ ball points

    openBall() {
        this.ball = { open: true, actions: [], action: null, step: null, suggested: null, zone: null };
        this.$wire.ballActions(this.t ?? 0).then((list) => {
            this.ball.actions = list;
            if (list.length) this.pickAction(list[0]);
        });
    },

    closeBall() {
        this.ball = { open: false, actions: [], action: null, step: null, suggested: null, zone: null };
        this.render();
    },

    pickAction(a) {
        this.ball.action = a;
        this.ball.suggested = null;
        this.ball.step = a.skill === 'reception' ? 'from' : 'to';
        this.render();
    },

    placeBall(evt) {
        const a = this.ball.action;
        if (!a) return;
        const c = this.toSvg(evt);
        const t = a.t ?? this.t ?? 0;
        const p = this.toTeam(c.x, c.y, t);
        const which = this.ball.step;
        const point = [round2(p.x), round2(p.y)];

        this.$wire.setBallPoint(a.id, which, point[0], point[1]).then((res) => {
            a[which] = point;
            this.ball.zone = res.zone;
            if (res.suggested) this.ball.suggested = res.suggested;
            this.ball.step = which === 'from' ? 'to' : null;
            this.render();
        });
    },

    applySuggested() {
        const a = this.ball.action;
        if (!a || !this.ball.suggested) return;
        this.$wire.resolveAction(a.id, null, this.ball.suggested).then(() => {
            a.grade = this.ball.suggested;
            this.ball.suggested = null;
        });
    },

    status() {
        if (this.t === null) return '';
        if (this.mode === 'rotation') return this.current ? 'rotation' : 'no-rotation';

        return this.shownSrc === null ? 'none' : 'ai';
    },
});
