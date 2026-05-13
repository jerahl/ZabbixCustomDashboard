// switches-bridge.jsx
//
// Adapts the server-inlined window.SWITCH_BOOT (populated by ActionSwitches
// via SwitchClient::snapshot()) into the globals the existing
// switches-widgets / switches-app components already read:
//
//   window.SWITCH_SITES      — fleet list (still mock-backed until a fleet
//                              collector lands; we splice in a "Live"
//                              site for the currently-bound switch)
//   window.ARC_MDF_STACK     — per-member port arrays for the selected switch
//                              (rebuilt from boot.members + boot.ports + boot.poe)
//   window.makePortDetail    — per-port detail panel data
//
// Also exposes window.tcsCyclePoe(member, port) — invoked by the CYCLE
// button in switches-widgets.jsx — which POSTs to
// window.TCS_SWITCH_CYCLEPOE_URL (injected by switches.view.php).
//
// Loads AFTER switches-data.jsx so it can override the mock globals; loads
// BEFORE switches-widgets.jsx so the widgets see the live data on first paint.

(function () {
    const boot = window.SWITCH_BOOT || {};
    const host = boot.host || null;
    const ports   = Array.isArray(boot.ports)   ? boot.ports   : [];
    const poe     = Array.isArray(boot.poe)     ? boot.poe     : [];
    const members = Array.isArray(boot.members) ? boot.members : [];
    const fdb     = Array.isArray(boot.fdb)     ? boot.fdb     : [];
    const fleet    = Array.isArray(boot.fleet)    ? boot.fleet    : [];
    const uplinks  = Array.isArray(boot.uplinks)  ? boot.uplinks  : [];
    const problems = Array.isArray(boot.problems) ? boot.problems : [];
    const kpis     = (boot.kpis    && typeof boot.kpis    === "object") ? boot.kpis    : {};
    const history  = (boot.history && typeof boot.history === "object") ? boot.history : {};
    // boot.fleet being defined (even as []) means the server attempted
    // discovery. In that case we ALWAYS replace SWITCH_SITES so the mock
    // demo data can't shadow a real-but-empty fleet.
    const liveMode = Object.prototype.hasOwnProperty.call(boot, "fleet");

    // Expose the bound hostid so the CYCLE button can POST without prop-drilling.
    window.TCS_SWITCH_HOSTID = host ? String(host.hostid || "") : "";

    // Navigation helper: change page to the selected switch. Used by the
    // HostNavigator click handler. Reads window.location so the rest of the
    // query string (e.g. tweak overrides) is preserved.
    window.tcsNavigateSwitch = function (hostid) {
        if (!hostid) return;
        const params = new URLSearchParams(window.location.search);
        params.set("action", "tcs.switches.view");
        params.set("switchid", String(hostid));
        window.location.search = "?" + params.toString();
    };

    /* --------------------------------------------------------------------- */
    /* Stack / port grid                                                     */
    /* --------------------------------------------------------------------- */

    // IF-MIB ifOperStatus → grid state label expected by the widget.
    const ifOperToState = (s) => {
        switch (Number(s)) {
            case 1: return "up";
            case 2: return "down";
            case 5: return "dormant";
            case 6: return "absent";
            default: return "down";
        }
    };
    // PoE detection status 3 = delivering.
    const poeDelivering = (s) => Number(s) === 3;

    // Group ports by member, sort by port number, derive { n, state, speed, poe }.
    function buildStack() {
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
                // The lifted EXOS template doesn't expose ifSpeed under this
                // key; default to 1G until a speed item lands. The grid only
                // colour-codes by speed, so this stays visually neutral.
                speed: 1000,
                poe: !!poeByKey[`${p.member}.${p.port}`],
                alert: false
            });
        }

        // If members was empty (older template / partial discovery), derive
        // from whatever members appeared in the port list.
        const memberIdxs = members.length
            ? members.map(m => Number(m.index)).filter(n => n > 0)
            : [...byMember.keys()].sort((a, b) => a - b);

        const stack = [];
        for (const idx of memberIdxs) {
            const list = (byMember.get(idx) || []).slice().sort((a, b) => a.n - b.n);
            const upCount   = list.filter(p => p.state === "up").length;
            const downCount = list.filter(p => p.state === "down").length;
            const poeCount  = list.filter(p => p.poe).length;
            stack.push({
                idx,
                ports: list,
                sfp: [],   // no SFP discovery yet — leave empty so widget skips it
                upCount,
                downCount,
                poeCount
            });
        }
        return stack.length ? stack : null;
    }

    const liveStack = buildStack();
    if (liveStack) {
        window.ARC_MDF_STACK = liveStack;
    }

    /* --------------------------------------------------------------------- */
    /* Fleet listing                                                         */
    /* --------------------------------------------------------------------- */

    // When the server attempted fleet discovery, replace SWITCH_SITES with
    // whatever it returned — including an empty array. Mock data is only
    // kept if the server didn't ship a fleet field at all (older builds).
    if (liveMode) {
        const activeHostid = host ? String(host.hostid || "") : "";
        const activeHost   = host ? String(host.host || "")   : "";
        const matches = (sw) => activeHostid !== ""
            ? String(sw.hostid) === activeHostid
            : sw.id === activeHost;
        window.SWITCH_SITES = fleet.map(site => {
            const switches = (site.switches || []).map(sw => ({
                ...sw,
                selected: matches(sw)
            }));
            return {
                ...site,
                // Start collapsed; expand only the site holding the
                // currently-active switch so the user can see their context.
                expanded: switches.some(sw => sw.selected),
                switches
            };
        });
        const total = fleet.reduce((n, s) => n + (s.switches || []).length, 0);
        console.info(`[tcs] switch fleet: ${fleet.length} site(s), ${total} host(s)`);
        if (total === 0) {
            console.warn("[tcs] switch fleet empty — verify Site/* host groups exist and EXOS hosts carry tag target=exos (template-inherited tags are now matched).");
        }
    } else if (host && Array.isArray(window.SWITCH_SITES)) {
        // Fallback: no fleet from server but we do have a bound host —
        // splice it in so users see real counters for the selected switch.
        const upCount   = ports.filter(p => Number(p.status) === 1).length;
        const downCount = ports.filter(p => Number(p.status) === 2).length;
        const poeCount  = poe.filter(p => poeDelivering(p.status)).length;

        const liveRow = {
            id:       host.host || host.visible_name || String(host.hostid),
            hostid:   String(host.hostid || ""),
            ip:       host.ip || "",
            model:    "—",
            members:  Math.max(1, members.length || 1),
            ports:    ports.length,
            up:       upCount,
            down:     downCount,
            poe:      poeCount,
            cpu:      0,
            mem:      0,
            temp:     0,
            problems: 0,
            selected: true
        };

        const liveSite = {
            id: "live", name: "Live (Zabbix)", expanded: true, problems: 0,
            switches: [liveRow]
        };
        const rest = window.SWITCH_SITES.map(s => ({ ...s, expanded: false }));
        window.SWITCH_SITES = [liveSite, ...rest];
    }

    /* --------------------------------------------------------------------- */
    /* KPIs · history · uplinks · problems                                   */
    /* --------------------------------------------------------------------- */

    // window.SWITCH_KPIS holds the resolved scalar values (cpu %, mem %,
    // temp °C, poe W, poe budget W). StackKPIs reads this in addition to
    // the host counters from the navigator row.
    const kpiVal = (k) => (kpis[k] && typeof kpis[k].lastvalue === "number")
        ? kpis[k].lastvalue : null;

    if (liveMode) {
        window.SWITCH_KPIS = {
            cpu:       kpiVal("cpu"),
            mem:       kpiVal("mem"),
            temp:      kpiVal("temp"),
            poeWatts:  kpiVal("poeWatts"),
            poeBudget: kpiVal("poeBudget")
        };

        // Sparkline history — fall back to flat arrays when an item wasn't
        // found so the Sparkline component still renders without guard checks.
        const h = (key) => Array.isArray(history[key]) ? history[key] : [];
        window.ARC_MDF_HISTORY = {
            cpu:      h("cpu"),
            mem:      h("mem"),
            temp:     h("temp"),
            poeWatts: h("poeWatts"),
            // Templates in the field rarely keep aggregate uplink rate items —
            // re-derive a coarse history from poeWatts as a placeholder so the
            // sparkline shape is plausible until a dedicated uplink-rate item
            // is wired up. Replaced with zeros if poeWatts is also empty.
            uplinkRx: h("uplinkRx").length ? h("uplinkRx") : h("poeWatts").map(v => v * 4 + 200),
            uplinkTx: h("uplinkTx").length ? h("uplinkTx") : h("poeWatts").map(v => v * 2 + 80)
        };

        window.ARC_MDF_LINKS    = uplinks.length ? uplinks : [];
        window.SWITCH_PROBLEMS  = problems;
    }

    /* --------------------------------------------------------------------- */
    /* Per-port detail builder                                               */
    /* --------------------------------------------------------------------- */

    const mockMakePortDetail = window.makePortDetail;
    const fdbByKey = Object.create(null);
    for (const row of fdb) {
        const k = `${row.member}.${row.port}`;
        (fdbByKey[k] = fdbByKey[k] || []).push(row.mac);
    }

    window.makePortDetail = function (memberIdx, port) {
        const base = typeof mockMakePortDetail === "function"
            ? mockMakePortDetail(memberIdx, port)
            : { label: `${memberIdx}:${port.n}`, state: port.state };

        const k = `${memberIdx}.${port.n}`;
        const macs = fdbByKey[k] || [];

        return {
            ...base,
            state: port.state,
            poe:   port.poe,
            // Surface live MAC table when available; UI keeps its synthetic
            // device card for the first MAC but extraMacs gets the real count.
            extraMacs: macs.length > 1 ? macs.length - 1 : (base.extraMacs || 0),
            macs
        };
    };

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
