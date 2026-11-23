/**
 * Club-wide real-time features:
 *  - presence: `$store.online.members` = people signed in to the club right now
 *  - activity toasts: new match, analysis finished, new member, sign-ins (staff)
 *
 * Configured by the app layout through `window.VVA` (see layouts/app.blade.php).
 */
const joined = new Set();

function format(template, params) {
    return Object.entries(params ?? {}).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value ?? ''), template);
}

function connect() {
    const cfg = window.VVA;

    if (!cfg?.clubId || !window.Echo) {
        return;
    }

    const store = window.Alpine.store('online');

    if (joined.has(cfg.clubId)) {
        return;
    }

    joined.forEach((id) => {
        window.Echo.leave(`online.clubs.${id}`);
        window.Echo.leave(`clubs.${id}`);
    });
    joined.clear();
    joined.add(cfg.clubId);
    store.members = [];

    window.Echo.join(`online.clubs.${cfg.clubId}`)
        .here((members) => (store.members = members))
        .joining((member) => {
            store.members = [...store.members.filter((m) => m.id !== member.id), member];
        })
        .leaving((member) => {
            store.members = store.members.filter((m) => m.id !== member.id);
        });

    window.Echo.private(`clubs.${cfg.clubId}`).listen('.activity', (event) => {
        const current = window.VVA;

        if (event.actor_id && event.actor_id === current.userId) {
            return;
        }

        if (event.audience === 'staff' && !current.isStaff) {
            return;
        }

        const params = { ...event.params };

        if (params.role && current.roles?.[params.role]) {
            params.role = current.roles[params.role];
        }

        const template = current.activity?.[event.type];

        if (template) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: format(template, params), tone: 'info', url: event.url } }));
        }
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('online', { members: [] });
});

document.addEventListener('livewire:navigated', connect);
