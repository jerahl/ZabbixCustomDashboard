// events-bridge.jsx — adapter for the Events Console.

(function () {
    const EMPTY = {
        events: [],
        metrics: {
            total: 0, fired: 0, resolved: 0,
            bySeverity: { disaster: 0, high: 0, warning: 0, info: 0 },
            timeline: new Array(24).fill(0),
            topHosts: [], mttrSec: 0, mttrStr: "—"
        },
        groups: [],
        filters: { severity: "", value: "any", groupids: "", search: "", range: "24h" },
        ts: 0
    };

    const apply = (b) => {
        const merged = { ...EMPTY, ...(b || {}) };
        merged.metrics = { ...EMPTY.metrics, ...(merged.metrics || {}) };
        if (!Array.isArray(merged.metrics.timeline) || merged.metrics.timeline.length !== 24) {
            merged.metrics.timeline = new Array(24).fill(0);
        }
        window.EVENTS_DATA = merged;
    };

    apply(window.EVENTS_BOOT);

    let currentFilters = { ...window.EVENTS_DATA.filters };
    const base = window.TCS_EVENTS_DATA_URL;
    const REFRESH_MS = 30_000;

    const fetchNow = async () => {
        if (!base) return;
        const qs = new URLSearchParams();
        for (const [k, v] of Object.entries(currentFilters)) {
            if (v !== "" && v != null) qs.set(k, v);
        }
        const url = qs.toString() ? `${base}&${qs}` : base;
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
        currentFilters = { ...currentFilters, ...(delta || {}) };
        return fetchNow();
    };
    window.tcsEventsGetFilters = () => ({ ...currentFilters });

    setInterval(fetchNow, REFRESH_MS);
})();
