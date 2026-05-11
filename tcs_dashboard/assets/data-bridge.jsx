// data-bridge.jsx
//
// Replaces the original mock-data data.jsx. Reads window.ZBX_BOOT (inlined by
// the PHP view) and exposes the same window globals the rest of the app
// already consumes. Then sets up a polling loop against TCS_DATA_URL so the
// values refresh without a full page reload.
//
// IMPORTANT: the consumer JSX (tabs.jsx, etc.) reads keys like
//   I.cpu.value, I.poeDraw.history, I.channelUtil5.value
// without guarding for missing keys, so we ALWAYS publish a fully-keyed
// ZBX_ITEMS object — even when the server returned nothing. Missing keys
// get a stub with value=null, history=[], missing=true; consumers then
// render dashes instead of crashing.

(function () {
    const STUB_HOST = {
        hostid: "",
        host: "—",
        visible_name: "Select a host (?hostid=NNNN)",
        ip: "",
        status: "unknown",
        available: 2,
        maintenance: 0,
        proxy: "",
        templates: [],
        groups: [],
        uptime: 0,
        lastSeen: "—"
    };

    // Every key the React app reads from window.ZBX_ITEMS. Keep this list in
    // sync with the $key_map in ActionDashboard::collectItems(). If you add
    // a new metric to either side, add it here too.
    const EXPECTED_ITEM_KEYS = [
        "cpu", "memory", "temp", "poeDraw",
        "uplinkIn", "uplinkOut", "pktLoss", "latency",
        "noise24", "noise5", "channelUtil24", "channelUtil5"
    ];

    const emptyItem = () => ({
        value: null,
        prev: null,
        unit: "",
        trigger: null,
        history: [],
        missing: true,
        key: ""
    });

    const buildItems = (serverItems) => {
        const out = {};
        for (const k of EXPECTED_ITEM_KEYS) {
            const incoming = serverItems && serverItems[k];
            if (incoming && typeof incoming === "object") {
                // Merge: server fields win, stub fills gaps. Defensive coercion
                // for history so .map / .length always work in consumers.
                out[k] = {
                    ...emptyItem(),
                    ...incoming,
                    history: Array.isArray(incoming.history) ? incoming.history : []
                };
            } else {
                out[k] = emptyItem();
            }
        }
        return out;
    };

    // Build a minimal AP_SITES list when the controller didn't supply one — the
    // APNavigator (shell.jsx) reads window.AP_SITES directly. With one host
    // we just put it under an "Active host" group; once collectApSites() is
    // implemented in ActionDashboard, the real fleet will replace this.
    const buildApSites = (boot, host) => {
        if (Array.isArray(boot && boot.apSites) && boot.apSites.length > 0) {
            return boot.apSites;
        }
        if (!host || !host.host) return [];
        return [{
            id: "active", name: host.site || "Active host", expanded: true, problems: 0,
            aps: [{
                id:       host.host,
                ip:       host.ip || "",
                model:    host.model || (Array.isArray(host.templates) && host.templates[0]) || "—",
                floor:    host.floor || "—",
                status:   host.available === 2 ? "down" : (host.available === 1 ? "ok" : "warn"),
                clients:  host.clients ?? 0,
                since:    host.lastSeen || "—",
                problems: 0,
                current:  true
            }]
        }];
    };

    function applyBoot(boot) {
        const b = boot || {};

        window.ZBX_HOST      = b.host        || STUB_HOST;
        window.ZBX_ITEMS     = buildItems(b.items);
        window.SYSTEM_INFO   = Array.isArray(b.systemInfo)  ? b.systemInfo  : [];
        window.NETWORK_INFO  = Array.isArray(b.networkInfo) ? b.networkInfo : [];
        window.PF_CLIENTS    = Array.isArray(b.pfClients)   ? b.pfClients   : [];
        window.PF_AUTH_FAILS = Array.isArray(b.pfAuthFails) ? b.pfAuthFails : [];
        window.ZBX_EVENTS    = Array.isArray(b.events)      ? b.events      : [];
        window.WIRED_PORTS   = Array.isArray(b.wiredPorts)  ? b.wiredPorts  : [];
        window.AP_SITES      = buildApSites(b, window.ZBX_HOST);
        window.ALERTS_SUMMARY = b.alerts || {
            associationFailures: 0, authFailures: 0,
            networkIssues: 0, packetLoss: 0,
            totalClients: 0, activeClients: 0
        };
    }

    // Initial paint comes from the server-inlined snapshot.
    applyBoot(window.ZBX_BOOT);

    // Tweak defaults expected by app.jsx — kept here so we don't have to
    // touch app.jsx at all.
    window.TWEAK_DEFAULTS = window.TWEAK_DEFAULTS || {
        accent: "#d92929",
        fontMono: "JetBrains Mono",
        density: "comfortable",
        showSourceBadges: true,
        showSidecar: true
    };

    // ----- Live refresh ------------------------------------------------------
    // Poll the JSON endpoint every 30s. The existing components read
    // window.ZBX_ITEMS each render, so updating the global is enough to
    // refresh on the next setState. If you'd rather wire real reactivity,
    // listen for the 'tcs:data' CustomEvent dispatched below.

    const REFRESH_MS = 30_000;
    const hostid = window.ZBX_HOST && window.ZBX_HOST.hostid;
    const url = window.TCS_DATA_URL;

    if (hostid && url) {
        const tick = async () => {
            try {
                const resp = await fetch(`${url}&hostid=${encodeURIComponent(hostid)}`, {
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" }
                });
                if (!resp.ok) return;
                const fresh = await resp.json();
                applyBoot({
                    ...(window.ZBX_BOOT || {}),
                    host:       fresh.host       ?? window.ZBX_HOST,
                    items:      fresh.items      ?? {},
                    events:     fresh.events     ?? window.ZBX_EVENTS,
                    alerts:     fresh.alerts     ?? window.ALERTS_SUMMARY,
                    wiredPorts: fresh.wiredPorts ?? window.WIRED_PORTS
                });
                window.dispatchEvent(new CustomEvent("tcs:data", { detail: fresh }));
            } catch (e) {
                console.warn("[tcs] data refresh failed:", e);
            }
        };
        setInterval(tick, REFRESH_MS);
    }
})();
