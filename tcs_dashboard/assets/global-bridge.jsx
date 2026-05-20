// global-bridge.jsx
//
// Adapts the JSON payload emitted by ActionGlobal / ActionGlobalData into the
// window globals global-app.jsx already reads: GLOBAL_TOTALS, GLOBAL_SITES,
// GLOBAL_DOMAINS, GLOBAL_TRIGGERS, GLOBAL_EVENTS, PROBLEM_TIMELINE.
//
// Loaded INSTEAD OF global-data.jsx — same window globals, real data instead
// of mock. Falls back to the synthetic shapes if the boot payload is missing,
// so loading order accidents render dashes rather than crashing.
//
// Exposes an imperative refresh API used by the header buttons:
//   window.tcsGlobalRefresh(rangeKey?)   — fetch immediately
//   window.tcsGlobalSetRange(rangeKey)   — change range AND refetch
// Dispatches "tcs:global-data" on every successful refresh with the parsed
// payload so the app can update the "Last refresh" timestamp.

(function () {
    const EMPTY_TOTALS = {
        hosts:     { total: 0, up: 0, down: 0, unknown: 0 },
        problems:  { disaster: 0, high: 0, warning: 0, info: 0, ack: 0 },
        sla:       { value: null, target: 99.5 },
        devices:   { total: null, online: null, quarantine: null, byod: null },
        proxies:   { total: 0, online: 0 },
        templates: { total: null, version: "—" }
    };

    // Range key → human label. Server-side parsed to a seconds window.
    const RANGES = {
        "1h":  "Last 1h",
        "6h":  "Last 6h",
        "24h": "Last 24h",
        "7d":  "Last 7d"
    };

    const normaliseSite = (s) => ({
        id:       s.id ?? "—",
        name:     s.name ?? "—",
        hosts:    s.hosts ?? 0,
        problems: s.problems ?? 0,
        sev:      s.sev ?? "ok",
        sla:      typeof s.sla === "number" ? s.sla : 100,
        kind:     s.kind ?? null,
        type:     s.type ?? null
    });

    const normaliseTotals = (t) => {
        const merged = { ...EMPTY_TOTALS, ...(t || {}) };
        if (typeof merged.sla?.value !== "number") {
            merged.sla = { ...merged.sla, value: merged.sla?.target ?? 100 };
        }
        if (typeof merged.templates?.total !== "number") {
            merged.templates = { ...merged.templates, total: 0 };
        }
        if (typeof merged.devices?.total !== "number") {
            merged.devices = { total: 0, online: 0, quarantine: 0, byod: 0 };
        }
        return merged;
    };

    const normaliseDomain = (d) => ({
        id:         d.id        ?? "—",
        label:      d.label     ?? "—",
        sub:        d.sub       ?? "",
        icon:       d.icon      ?? "ap",
        src:        d.src       ?? "zbx",
        status:     d.status    ?? "ok",
        href:       d.href      ?? "#",
        total:      d.total     ?? 0,
        ok:         d.ok        ?? 0,
        warn:       d.warn      ?? 0,
        err:        d.err       ?? 0,
        problems:   d.problems  ?? 0,
        top:        d.top       ?? "",
        kpis:       Array.isArray(d.kpis) ? d.kpis : [],
        spark:      Array.isArray(d.spark) && d.spark.length === 24
                        ? d.spark
                        : new Array(24).fill(0),
        sparkColor: d.sparkColor ?? "var(--zbx)",
        sparkLabel: d.sparkLabel ?? ""
    });

    const applyBoot = (boot) => {
        const b = boot || {};
        window.GLOBAL_TOTALS    = normaliseTotals(b.totals);
        window.GLOBAL_SITES     = Array.isArray(b.sites)    ? b.sites.map(normaliseSite)   : [];
        window.GLOBAL_DOMAINS   = Array.isArray(b.domains)  ? b.domains.map(normaliseDomain) : [];
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

    // --- Live refresh state ---
    let currentRange = "24h";
    const REFRESH_MS = 30_000;
    const baseUrl = window.TCS_GLOBAL_DATA_URL;

    const fetchNow = async () => {
        if (!baseUrl) return;
        const url = `${baseUrl}&range=${encodeURIComponent(currentRange)}`;
        try {
            const resp = await fetch(url, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) return;
            const fresh = await resp.json();
            applyBoot(fresh);
            window.dispatchEvent(new CustomEvent("tcs:global-data", {
                detail: { ...fresh, range: currentRange, fetchedAt: Date.now() }
            }));
        } catch (e) {
            console.warn("[tcs] global refresh failed:", e);
        }
    };

    window.tcsGlobalRefresh = fetchNow;
    window.tcsGlobalSetRange = (r) => {
        if (!RANGES[r]) return;
        currentRange = r;
        return fetchNow();
    };
    window.tcsGlobalRanges = RANGES;

    setInterval(fetchNow, REFRESH_MS);
})();
