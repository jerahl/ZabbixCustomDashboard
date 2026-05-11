// servers-bridge.jsx
//
// Replaces servers-data.jsx. Reads window.SERVERS_BOOT (inlined by the view)
// and publishes the window globals servers-app.jsx / servers-widgets.jsx
// already consume:
//   - SERVER_SITES, SERVER_PROBLEMS
//   - ACTIVE_SERVER_HISTORY, ACTIVE_SERVER_FS, ACTIVE_SERVER_IFACES,
//     ACTIVE_SERVER_SERVICES, ACTIVE_SERVER_PROCS, ACTIVE_SERVER_SESSIONS
//
// When ?hostid is not provided, the "active" block is empty — the right-hand
// detail panel just renders dashes.

(function () {
    const EMPTY_HISTORY = {
        cpu1m: [], cpu5m: [], memUsed: [],
        diskRead: [], diskWrite: [], netIn: [], netOut: [],
        swap: [], load1m: []
    };

    const applyBoot = (boot) => {
        const b = boot || {};
        window.SERVER_SITES    = Array.isArray(b.sites)    ? b.sites    : [];
        window.SERVER_PROBLEMS = Array.isArray(b.problems) ? b.problems : [];

        const active = b.active || {};
        window.ACTIVE_SERVER_HISTORY  = { ...EMPTY_HISTORY, ...(active.history || {}) };
        window.ACTIVE_SERVER_FS       = Array.isArray(active.fs)       ? active.fs       : [];
        window.ACTIVE_SERVER_IFACES   = Array.isArray(active.ifaces)   ? active.ifaces   : [];
        window.ACTIVE_SERVER_SERVICES = Array.isArray(active.services) ? active.services : [];
        window.ACTIVE_SERVER_PROCS    = Array.isArray(active.procs)    ? active.procs    : [];
        window.ACTIVE_SERVER_SESSIONS = Array.isArray(active.sessions) ? active.sessions : [];
    };

    applyBoot(window.SERVERS_BOOT);

    window.TWEAK_DEFAULTS = window.TWEAK_DEFAULTS || {
        accent: "#d92929",
        fontMono: "JetBrains Mono",
        density: "comfortable",
        showSourceBadges: true,
        showSidecar: true
    };

    const REFRESH_MS = 30_000;
    const url = window.TCS_SERVERS_DATA_URL;
    if (!url) return;

    const hostid = window.SERVERS_HOSTID || "";
    const fullUrl = hostid ? `${url}&hostid=${encodeURIComponent(hostid)}` : url;

    const tick = async () => {
        try {
            const resp = await fetch(fullUrl, {
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!resp.ok) return;
            const fresh = await resp.json();
            applyBoot(fresh);
            window.dispatchEvent(new CustomEvent("tcs:servers-data", { detail: fresh }));
        } catch (e) {
            console.warn("[tcs] servers refresh failed:", e);
        }
    };
    setInterval(tick, REFRESH_MS);
})();
