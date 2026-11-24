/**
 * Tabs of the match page side panel (timeline, quick pick, coding, review queue).
 * Remembers the last tab per browser; falls back to the first one.
 */
const KEY = 'vva.matchPanelTab';

export default (tabs = []) => ({
    tabs,
    tab: tabs[0] ?? null,

    init() {
        try {
            const saved = window.localStorage.getItem(KEY);

            if (saved && this.tabs.includes(saved)) {
                this.tab = saved;
            }
        } catch (e) {
            // storage blocked: keep the default tab
        }
    },

    show(tab) {
        this.tab = tab;

        try {
            window.localStorage.setItem(KEY, tab);
        } catch (e) {
            // ignore
        }
    },
});
