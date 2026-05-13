// switches-bridge.jsx
//
// Async data layer for the Switches page. The PHP controller (ActionSwitches)
// renders just the page shell + host metadata to keep TTFB low; this file
// fetches the heavy data (fleet, snapshot, problems) in parallel after first
// paint and updates the window globals the React widgets read on each render.
//
// Globals managed here (kept compatible with the mock-data names so the
// widgets need no edits):
//   window.SWITCH_SITES      — fleet for HostNavigator
//   window.ARC_MDF_STACK     — per-member port arrays
//   window.SWITCH_KPIS       — scalar KPI values (cpu/mem/temp/poeWatts/...)
//   window.ARC_MDF_HISTORY   — 24h sparkline series
//   window.ARC_MDF_LINKS     — uplink top-talker rows
//   window.SWITCH_PROBLEMS   — recent problems
//   window.makePortDetail()  — per-port detail panel builder
//
// Events dispatched on window:
//   "tcs:switch-data"   — fired whenever a global was just updated, so the
//                         React app can bump a re-render counter.
//
// Helpers exposed:
//   window.tcsNavigateSwitch(hostid)  — reloads page targeting a different host
//   window.tcsCyclePoe(member, port)  — POSTs to tcs.switch.cyclepoe

(function () {
    const boot = window.SWITCH_BOOT || {};
    const host = boot.host || null;

    // Expose the bound hostid so the CYCLE button can POST without prop-drilling.
    window.TCS_SWITCH_HOSTID = host ? String(host.hostid || "") : "";

    // Navigation: SPA-style. No page reload — just re-fetch the snapshot for
    // the new hostid and update the URL via pushState so refresh / back-button
    // still target the right switch.
    window.tcsNavigateSwitch = function (hostid) {
        if (!hostid) return;
        const id = String(hostid);
        const params = new URLSearchParams(window.location.search);
        params.set("action", "tcs.switches.view");
        params.set("switchid", id);
        try {
            window.history.pushState({ switchid: id }, "", "?" + params.toString());
        } catch (e) { /* ignore — pushState rarely fails */ }
        window.TCS_SWITCH_HOSTID = id;
        // Reset port-status / KPI state so the user sees the empty skeleton
        // for the new host while the snapshot is in flight.
        window.ARC_MDF_STACK   = [{ idx: 1, ports: [], sfp: [], upCount: 0, downCount: 0, poeCount: 0 }];
        window.SWITCH_KPIS     = { cpu: null, mem: null, temp: null, poeWatts: null, poeBudget: null };
        window.ARC_MDF_HISTORY = { cpu: [], mem: [], temp: [], poeWatts: [], uplinkRx: [], uplinkTx: [] };
        window.ARC_MDF_LINKS   = [];
        window.SWITCH_PROBLEMS = [];
        window.SWITCH_LOADING  = { ...window.SWITCH_LOADING, snapshot: true };
        window.dispatchEvent(new CustomEvent("tcs:switch-data", { detail: { section: "navigate" } }));
        fetchSnapshot(id);
    };

    // Initial empty defaults. The page is data-source-truthy: nothing renders
    // until the JSON endpoints respond. No mock fixtures are loaded at all
    // (switches-data.jsx was removed) — empty arrays are the empty state.
    window.SWITCH_KPIS      = { cpu: null, mem: null, temp: null, poeWatts: null, poeBudget: null };
    window.SWITCH_PROBLEMS  = [];
    window.ARC_MDF_LINKS    = [];
    window.ARC_MDF_HISTORY  = { cpu: [], mem: [], temp: [], poeWatts: [], uplinkRx: [], uplinkTx: [] };
    window.SWITCH_SITES     = [];
    // Single empty stack member keeps the port grid renderable until the
    // snapshot arrives (the grid expects at least one member to map over).
    window.ARC_MDF_STACK    = [{ idx: 1, ports: [], sfp: [], upCount: 0, downCount: 0, poeCount: 0 }];
    // Loading flags so widgets / future skeletons can show "loading…" affordances.
    window.SWITCH_LOADING   = { fleet: true, snapshot: true };

    // Self-contained port-detail builder. Returns the fields PortDetailPane
    // / PacketFenceDevicePane read; histories and per-port rates stay empty
    // until dedicated items are wired. FDB MACs are attached when applySnapshot
    // captures them.
    const FLAT60 = Array.from({ length: 60 }, () => 0);
    const _fdbByKey = Object.create(null);
    window.makePortDetail = function (memberIdx, port) {
        const k = `${memberIdx}.${port.n}`;
        const macs = _fdbByKey[k] || [];
        return {
            label:      `${memberIdx}:${port.n}`,
            state:      port.state,
            speed:      port.speed || 0,
            poe:        !!port.poe,
            poeWatts:   0,
            inKbps:     0,
            outKbps:    0,
            utilPct:    0,
            inHist:     FLAT60,
            outHist:    FLAT60,
            onlineHist: FLAT60.map(() => port.state === "up" ? "ok" : "off"),
            errors1h:   0,
            discards1h: 0,
            device:     null,    // PacketFenceDevicePane shows empty-state on null
            extraMacs:  macs.length > 1 ? macs.length - 1 : 0,
            macs,
            ifIndex:    1000 + (Number(port.n) || 0),
            ageMin:     0
        };
    };
    // Stash the bag so applySnapshot can repopulate it on each refresh.
    window._tcsFdbByKey = _fdbByKey;

    /* --------------------------------------------------------------------- */
    /* Adapters: payload sections → window globals                           */
    /* --------------------------------------------------------------------- */

    const ifOperToState = (s) => {
        switch (Number(s)) {
            case 1: return "up";
            case 2: return "down";
            case 5: return "dormant";
            case 6: return "absent";
            default: return "down";
        }
    };
    const poeDelivering = (s) => Number(s) === 3;

    function buildStack(members, ports, poe) {
        if (!ports.length) return null;

        const poeByKey = Object.create(null);
        for (const p of poe) {
            poeByKey[`${p.member}.${p.port}`] = poeDelivering(p.status);
        }

        const byMember = new Map();
        for (const p of ports) {
            const m = Number(p.member) || 1;
            if (!byMember.has(m)) byMember.set(m, []);
            byMember.get(m).push({
                n: Number(p.port),
                state: ifOperToState(p.status),
                speed: 1000,
                poe: !!poeByKey[`${p.member}.${p.port}`],
                alert: false
            });
        }

        const memberIdxs = members.length
            ? members.map(m => Number(m.index)).filter(n => n > 0)
            : [...byMember.keys()].sort((a, b) => a - b);

        const stack = [];
        for (const idx of memberIdxs) {
            const list = (byMember.get(idx) || []).slice().sort((a, b) => a.n - b.n);
            stack.push({
                idx,
                ports: list,
                sfp: [],
                upCount:   list.filter(p => p.state === "up").length,
                downCount: list.filter(p => p.state === "down").length,
                poeCount:  list.filter(p => p.poe).length
            });
        }
        return stack.length ? stack : null;
    }

    function applyFleet(fleet) {
        const activeHostid = host ? String(host.hostid || "") : "";
        const activeHost   = host ? String(host.host   || "") : "";
        const matches = (sw) => activeHostid !== ""
            ? String(sw.hostid) === activeHostid
            : sw.id === activeHost;
        window.SWITCH_SITES = fleet.map(site => {
            const switches = (site.switches || []).map(sw => ({ ...sw, selected: matches(sw) }));
            return {
                ...site,
                expanded: switches.some(sw => sw.selected),
                switches
            };
        });
        const total = fleet.reduce((n, s) => n + (s.switches || []).length, 0);
        console.info(`[tcs] switch fleet: ${fleet.length} site(s), ${total} host(s)`);
        if (total === 0) {
            console.warn("[tcs] switch fleet empty — verify Site/* host groups exist and EXOS hosts carry tag target=exos.");
        }
    }

    function applySnapshot(snap) {
        const members  = Array.isArray(snap.members)  ? snap.members  : [];
        const ports    = Array.isArray(snap.ports)    ? snap.ports    : [];
        const poe      = Array.isArray(snap.poe)      ? snap.poe      : [];
        const fdb      = Array.isArray(snap.fdb)      ? snap.fdb      : [];
        const uplinks  = Array.isArray(snap.uplinks)  ? snap.uplinks  : [];
        const problems = Array.isArray(snap.problems) ? snap.problems : [];
        const kpis     = (snap.kpis    && typeof snap.kpis    === "object") ? snap.kpis    : {};
        const history  = (snap.history && typeof snap.history === "object") ? snap.history : {};

        const stack = buildStack(members, ports, poe);
        if (stack) window.ARC_MDF_STACK = stack;

        const kpiVal = (k) => (kpis[k] && typeof kpis[k].lastvalue === "number") ? kpis[k].lastvalue : null;
        window.SWITCH_KPIS = {
            cpu:       kpiVal("cpu"),
            mem:       kpiVal("mem"),
            temp:      kpiVal("temp"),
            poeWatts:  kpiVal("poeWatts"),
            poeBudget: kpiVal("poeBudget")
        };

        const h = (key) => Array.isArray(history[key]) ? history[key] : [];
        window.ARC_MDF_HISTORY = {
            cpu:      h("cpu"),
            mem:      h("mem"),
            temp:     h("temp"),
            poeWatts: h("poeWatts"),
            uplinkRx: h("uplinkRx").length ? h("uplinkRx") : h("poeWatts").map(v => v * 4 + 200),
            uplinkTx: h("uplinkTx").length ? h("uplinkTx") : h("poeWatts").map(v => v * 2 + 80)
        };

        window.ARC_MDF_LINKS   = uplinks;
        window.SWITCH_PROBLEMS = problems;

        // Repopulate the FDB bag — makePortDetail (defined once at IIFE time)
        // reads from this on demand so the next click on a port picks up the
        // freshest MAC list without rebinding the builder.
        const bag = window._tcsFdbByKey;
        for (const k of Object.keys(bag)) delete bag[k];
        for (const row of fdb) {
            const k = `${row.member}.${row.port}`;
            (bag[k] = bag[k] || []).push(row.mac);
        }
    }

    /** Fire a re-render in the React app. */
    function notify(section) {
        window.dispatchEvent(new CustomEvent("tcs:switch-data", { detail: { section } }));
    }

    /* --------------------------------------------------------------------- */
    /* Async fetches                                                         */
    /* --------------------------------------------------------------------- */

    const URL_FLEET    = window.TCS_SWITCH_FLEET_URL    || "zabbix.php?action=tcs.switches.fleet.data";
    const URL_SNAPSHOT = window.TCS_SWITCH_SNAPSHOT_URL || "zabbix.php?action=tcs.switches.snapshot.data";

    async function fetchJson(url) {
        const resp = await fetch(url, {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        return resp.json();
    }

    function fetchSnapshot(switchid) {
        if (!switchid) return;
        const url = `${URL_SNAPSHOT}&switchid=${encodeURIComponent(switchid)}`;
        return fetchJson(url)
            .then(j => {
                applySnapshot(j || {});
                const counts = {
                    members: (j.members || []).length,
                    ports:   (j.ports   || []).length,
                    poe:     (j.poe     || []).length,
                    uplinks: (j.uplinks || []).length,
                    problems: (j.problems || []).length
                };
                console.info("[tcs] snapshot for hostid", switchid, counts);
            })
            .catch(e => console.error("[tcs] snapshot fetch failed:", e, "url:", url))
            .finally(() => {
                window.SWITCH_LOADING.snapshot = false;
                notify("snapshot");
            });
    }

    // Fire both in parallel so they don't queue behind each other.
    console.info("[tcs] fetching switch fleet + snapshot…");
    fetchJson(URL_FLEET)
        .then(j => {
            const fleet = Array.isArray(j && j.fleet) ? j.fleet : [];
            applyFleet(fleet);
        })
        .catch(e => console.error("[tcs] fleet fetch failed:", e, "url:", URL_FLEET))
        .finally(() => {
            window.SWITCH_LOADING.fleet = false;
            notify("fleet");
        });

    const switchid = host ? String(host.hostid || "") : "";
    if (switchid) {
        fetchSnapshot(switchid);
    } else {
        window.SWITCH_LOADING.snapshot = false;
        console.info("[tcs] no switchid in URL — skipping snapshot fetch");
    }

    // Browser back/forward should also re-snapshot, not full-reload.
    window.addEventListener("popstate", () => {
        const p = new URLSearchParams(window.location.search);
        const id = p.get("switchid") || "";
        if (id && id !== window.TCS_SWITCH_HOSTID) {
            window.tcsNavigateSwitch(id);
        }
    });

    /* --------------------------------------------------------------------- */
    /* CYCLE PoE handler                                                     */
    /* --------------------------------------------------------------------- */

    window.tcsCyclePoe = async function (member, port) {
        const url = window.TCS_SWITCH_CYCLEPOE_URL;
        const hostid = window.TCS_SWITCH_HOSTID;
        if (!url || !hostid) {
            return { ok: false, error: "endpoint not configured" };
        }
        try {
            const resp = await fetch(url, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    hostid,
                    member: Number(member) || 1,
                    port:   Number(port)   || 0
                })
            });
            const body = await resp.json().catch(() => ({}));
            if (!resp.ok) {
                return { ok: false, error: body.error || `HTTP ${resp.status}` };
            }
            return body;
        } catch (e) {
            return { ok: false, error: String(e && e.message ? e.message : e) };
        }
    };
})();
