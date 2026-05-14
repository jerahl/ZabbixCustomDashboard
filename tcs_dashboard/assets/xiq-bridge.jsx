// xiq-bridge.jsx
//
// Data layer for the XIQ Wireless Status page. ActionXiq embeds the SSR boot
// snapshot in window.XIQ_BOOT. This bridge unpacks it into the per-section
// globals xiq-app.jsx reads (XIQ_TOTALS / XIQ_SITES / XIQ_BANDS / XIQ_SSIDS /
// XIQ_PROBLEM_APS / XIQ_CHANNEL_GRID / XIQ_CLIENT_MIX / XIQ_THROUGHPUT /
// XIQ_FIRMWARE / XIQ_ROAMING / XIQ_EVENTS), then fetches tcs.xiq.data after
// first paint to refresh the same globals and bump a re-render.
//
// Mirrors the switches-bridge pattern.

(function () {
    const KEYS = [
        ["totals",      "XIQ_TOTALS",       {}],
        ["sites",       "XIQ_SITES",        []],
        ["bands",       "XIQ_BANDS",        []],
        ["ssids",       "XIQ_SSIDS",        []],
        ["problemAps",  "XIQ_PROBLEM_APS",  []],
        ["channelGrid", "XIQ_CHANNEL_GRID", { sites: [], channels: [], matrix: [] }],
        ["clientMix",   "XIQ_CLIENT_MIX",   { standards: [], os: [] }],
        ["throughput",  "XIQ_THROUGHPUT",   []],
        ["firmware",    "XIQ_FIRMWARE",     { versions: [] }],
        ["roaming",     "XIQ_ROAMING",      { buckets: [], rate24h: 0 }],
        ["events",      "XIQ_EVENTS",       []],
    ];

    function apply(payload) {
        if (!payload || typeof payload !== "object") return;
        for (const [src, dst, fallback] of KEYS) {
            const v = payload[src];
            window[dst] = (v !== undefined && v !== null) ? v : (window[dst] || fallback);
        }
        window.dispatchEvent(new CustomEvent("tcs:xiq-data", { detail: { ts: payload.ts || Date.now() } }));
    }

    // First paint: unpack the SSR boot synchronously so xiq-app.jsx sees
    // populated globals on its initial render.
    apply(window.XIQ_BOOT || {});

    const URL_DATA = window.TCS_XIQ_DATA_URL || "zabbix.php?action=tcs.xiq.data";

    async function fetchData() {
        try {
            const resp = await fetch(URL_DATA, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const j = await resp.json();
            apply(j || {});
            console.info("[tcs] xiq data refreshed:",
                (j.sites || []).length, "site(s),",
                ((j.totals && j.totals.aps && j.totals.aps.total) || 0), "AP(s)");
        } catch (e) {
            console.error("[tcs] xiq data fetch failed:", e, "url:", URL_DATA);
        }
    }

    console.info("[tcs] fetching XIQ snapshot…");
    fetchData();

    // Auto-refresh every 30s. Skip when the tab is hidden so a backgrounded
    // dashboard doesn't keep hammering the Zabbix server. Server-side caches
    // for 30s anyway, so this is the natural cadence.
    const REFRESH_MS = 30_000;
    setInterval(() => {
        if (document.visibilityState === "visible") fetchData();
    }, REFRESH_MS);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") fetchData();
    });

    // Expose a manual refresh hook for the Tweaks "Refresh now" button.
    window.tcsXiqRefresh = fetchData;
})();
