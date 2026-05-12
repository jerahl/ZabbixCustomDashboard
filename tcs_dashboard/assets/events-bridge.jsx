// events-bridge.jsx
//
// Adapts the ActionEvents / ActionEventsData payload into the window globals
// the designed events-app.jsx consumes:
//
//   EV_EVENTS, EV_TIMELINE, EV_SITES, EV_HOSTGROUPS, EV_TAGS,
//   EV_SAVED_VIEWS, EV_METRICS, EV_FILTERS
//
// Imperative API:
//   tcsEventsRefresh()        — refetch with current range
//   tcsEventsFetch({range})   — change range AND refetch
//
// Dispatches "tcs:events-data" on every successful refresh.

(function () {
    const EMPTY = {
        events: [],
        timeline: new Array(24).fill(null).map(() => [0, 0, 0, 0]),
        sites: [],
        hostgroups: [],
        tags: [],
        savedViews: [],
        metrics: { open: 0, ack: 0, mttaStr: "—", mttrStr: "—", mttrSec: 0 },
        range: "24h",
        ts: 0
    };

    const apply = (b) => {
        const m = { ...EMPTY, ...(b || {}) };
        m.timeline   = (Array.isArray(m.timeline) && m.timeline.length === 24) ? m.timeline : EMPTY.timeline;
        m.events     = Array.isArray(m.events)     ? m.events     : [];
        m.sites      = Array.isArray(m.sites)      ? m.sites      : [];
        m.hostgroups = Array.isArray(m.hostgroups) ? m.hostgroups : [];
        m.tags       = Array.isArray(m.tags)       ? m.tags       : [];
        m.savedViews = Array.isArray(m.savedViews) ? m.savedViews : [];
        m.metrics    = { ...EMPTY.metrics, ...(m.metrics || {}) };

        window.EV_EVENTS       = m.events;
        window.EV_TIMELINE     = m.timeline;
        window.EV_SITES        = m.sites;
        window.EV_HOSTGROUPS   = m.hostgroups;
        window.EV_TAGS         = m.tags;
        window.EV_SAVED_VIEWS  = m.savedViews;
        window.EV_METRICS      = m.metrics;
        window.EV_FILTERS      = { range: m.range };
    };

    apply(window.EVENTS_BOOT);

    let currentRange = (window.EV_FILTERS && window.EV_FILTERS.range) || "24h";
    const base = window.TCS_EVENTS_DATA_URL;
    const REFRESH_MS = 30_000;

    const fetchNow = async () => {
        if (!base) return;
        const url = `${base}&range=${encodeURIComponent(currentRange)}`;
        try {
            const resp = await fetch(url, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) return;
            const fresh = await resp.json();
            apply(fresh);
            window.dispatchEvent(new CustomEvent("tcs:events-data", {
                detail: { ...fresh, fetchedAt: Date.now() }
            }));
        } catch (e) {
            console.warn("[tcs] events fetch failed:", e);
        }
    };

    window.tcsEventsRefresh = fetchNow;
    window.tcsEventsFetch = (delta) => {
        if (delta && delta.range) currentRange = delta.range;
        return fetchNow();
    };

    setInterval(fetchNow, REFRESH_MS);
})();
