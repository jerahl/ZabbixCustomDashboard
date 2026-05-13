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

    // Expose the bound hostid so the CYCLE button can POST without prop-drilling.
    window.TCS_SWITCH_HOSTID = host ? String(host.hostid || "") : "";

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

    // Until a fleet collector exists, splice the bound switch into the mock
    // SWITCH_SITES so it appears as the selected host with real counters.
    if (host && Array.isArray(window.SWITCH_SITES)) {
        const upCount   = ports.filter(p => Number(p.status) === 1).length;
        const downCount = ports.filter(p => Number(p.status) === 2).length;
        const poeCount  = poe.filter(p => poeDelivering(p.status)).length;

        const liveRow = {
            id:       host.host || host.visible_name || String(host.hostid),
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
        window.SWITCH_SITES = [liveSite, ...window.SWITCH_SITES];
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
