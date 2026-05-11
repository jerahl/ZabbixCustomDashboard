// global-bridge.jsx
//
// Adapts the JSON payload emitted by ActionGlobal / ActionGlobalData into the
// window globals global-app.jsx already reads: GLOBAL_TOTALS, GLOBAL_SITES,
// GLOBAL_DOMAINS, GLOBAL_TRIGGERS, GLOBAL_EVENTS, PROBLEM_TIMELINE.
//
// Loaded INSTEAD OF global-data.jsx — same window globals, real data instead
// of mock. Falls back to the synthetic shapes if the boot payload is missing,
// so loading order accidents render dashes rather than crashing.

(function () {
    const EMPTY_TOTALS = {
        hosts:     { total: 0, up: 0, down: 0, unknown: 0 },
        problems:  { disaster: 0, high: 0, warning: 0, info: 0, ack: 0 },
        sla:       { value: null, target: 99.5 },
        devices:   { total: null, online: null, quarantine: null, byod: null },
        proxies:   { total: 0, online: 0 },
        templates: { total: null, version: "—" }
    };

    const applyBoot = (boot) => {
        const b = boot || {};
        window.GLOBAL_TOTALS    = { ...EMPTY_TOTALS, ...(b.totals || {}) };
        window.GLOBAL_SITES     = Array.isArray(b.sites)    ? b.sites    : [];
        window.GLOBAL_DOMAINS   = Array.isArray(b.domains)  ? b.domains  : [];
        window.GLOBAL_TRIGGERS  = Array.isArray(b.triggers) ? b.triggers : [];
        window.GLOBAL_EVENTS    = Array.isArray(b.events)   ? b.events   : [];
        window.PROBLEM_TIMELINE = Array.isArray(b.timeline) && b.timeline.length === 24
            ? b.timeline
            : new Array(24).fill(0);
    };

    applyBoot(window.GLOBAL_BOOT);

    window.TWEAK_DEFAULTS = window.TWEAK_DEFAULTS || {
        accent: "#d92929",
        fontMono: "JetBrains Mono",
        density: "comfortable",
        showSourceBadges: true,
        showSidecar: true
    };

    const REFRESH_MS = 30_000;
    const url = window.TCS_GLOBAL_DATA_URL;
    if (!url) return;

    const tick = async () => {
        try {
            const resp = await fetch(url, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) return;
            const fresh = await resp.json();
            applyBoot(fresh);
            window.dispatchEvent(new CustomEvent("tcs:global-data", { detail: fresh }));
        } catch (e) {
            console.warn("[tcs] global refresh failed:", e);
        }
    };
    setInterval(tick, REFRESH_MS);
})();
