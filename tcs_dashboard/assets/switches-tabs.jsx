// Switches dashboard — extra tab views (Topology, Stack Health, VLAN, PoE, Macros/CLI, Triggers, Backups)

const { useState: useStateTAB } = React;

// ───────────────────────────────────────────────────────────────────
// Shared data for tab content
// ───────────────────────────────────────────────────────────────────

window.TAB_TOPOLOGY = {
  upstreams: [
    { id: "core-arc-1", port: "Te1/0/13", role: "core",  type: "10G SR", util: 18, status: "up" },
    { id: "core-arc-2", port: "Te1/0/13", role: "core",  type: "10G SR", util: 16, status: "up" },
  ],
  downstreams: [
    { id: "ARC-GYM",     port: "1:25",  type: "1G",    util: 5,   status: "up" },
    { id: "ARC-IDF-109", port: "1:49",  type: "10G SR", util: 10, status: "up" },
    { id: "ARC-IDF-217", port: "1:49",  type: "10G SR", util: 4,  status: "up", errors: 2 },
    { id: "ARCSBC",      port: "1:23",  type: "1G",    util: 1,   status: "down" },
  ],
  lldp: [
    { local: "1:57", remote: "core-arc-1",   remPort: "Te1/0/13", sysDesc: "Aruba CX 8325-48Y8C",     macAge: "12h" },
    { local: "1:59", remote: "core-arc-2",   remPort: "Te1/0/13", sysDesc: "Aruba CX 8325-48Y8C",     macAge: "12h" },
    { local: "2:57", remote: "ARC-IDF-109",  remPort: "1:49",     sysDesc: "Extreme 5520-48T EXOS 31.7.1.4", macAge: "4h"  },
    { local: "3:59", remote: "ARC-IDF-217",  remPort: "1:49",     sysDesc: "Extreme 5320-48P-8XE EXOS 31.7.1.4", macAge: "1h"  },
    { local: "1:25", remote: "ARC-GYM",      remPort: "1:25",     sysDesc: "Extreme 5520-24T EXOS 31.7.1.4", macAge: "2d"  },
    { local: "1:23", remote: "ARCSBC",       remPort: "1:23",     sysDesc: "Extreme 5320-24P-8XE", macAge: "15m" },
  ],
};

window.TAB_STACK_HEALTH = [
  { idx: 1, role: "Backup",  serial: "1903N-72101", uptime: "127d 04h", cpu: 22, cpu5: 18, mem: 36, temp: 67,  fan1: 5400, fan2: 5320, psu1: 240, psu2: 238, version: "31.7.1.4" },
  { idx: 2, role: "Primary", serial: "1903N-72104", uptime: "127d 04h", cpu: 28, cpu5: 24, mem: 38, temp: 69,  fan1: 5480, fan2: 5410, psu1: 244, psu2: 240, version: "31.7.1.4" },
  { idx: 3, role: "Standby", serial: "1903N-72107", uptime: "127d 04h", cpu: 25, cpu5: 22, mem: 36, temp: 71,  fan1: 5620, fan2: 5580, psu1: 236, psu2: 0,   version: "31.7.1.4", warn: "PSU2 absent" },
  { idx: 4, role: "Backup",  serial: "1903N-72112", uptime: "  6d 11h", cpu: 19, cpu5: 17, mem: 34, temp: 65,  fan1: 5380, fan2: 5290, psu1: 232, psu2: 230, version: "31.7.1.4" },
];

// Sparkline seed generator (deterministic small history)
function _spark(seed, base, jitter, len = 24) {
  let x = seed;
  return Array.from({ length: len }, () => {
    x = (x * 9301 + 49297) % 233280;
    return Math.round(base + (x / 233280 - 0.5) * 2 * jitter);
  });
}

window.TAB_VLANS = [
  { id: 1,    name: "default",     ports: 12,  tagged: 0,  ip: "—",            desc: "system default",         active: false },
  { id: 10,   name: "MGMT",        ports: 0,   tagged: 4,  ip: "10.24.0.1/24",  desc: "Switch management",      active: true  },
  { id: 20,   name: "FACULTY",     ports: 38,  tagged: 4,  ip: "10.24.20.1/22", desc: "Domain joined laptops",  active: true  },
  { id: 30,   name: "STUDENT",     ports: 24,  tagged: 4,  ip: "10.24.30.1/22", desc: "Student devices",        active: true  },
  { id: 40,   name: "BYOD",        ports: 0,   tagged: 4,  ip: "10.24.40.1/22", desc: "PacketFence-isolated",   active: true  },
  { id: 50,   name: "AV-WAP",      ports: 14,  tagged: 4,  ip: "10.24.50.1/24", desc: "Wireless APs + cameras", active: true  },
  { id: 60,   name: "VOIP",        ports: 8,   tagged: 4,  ip: "10.24.60.1/24", desc: "Cisco/Polycom phones",   active: true  },
  { id: 80,   name: "PRINTERS",    ports: 6,   tagged: 4,  ip: "10.24.80.1/24", desc: "Printers + copiers",     active: true  },
  { id: 90,   name: "GUEST",       ports: 0,   tagged: 4,  ip: "10.24.90.1/24", desc: "Visitor wifi",           active: true  },
  { id: 100,  name: "CAMERAS",     ports: 4,   tagged: 4,  ip: "10.24.100.1/24",desc: "AvigilonALTA cameras",   active: true  },
  { id: 200,  name: "ISOLATION",   ports: 0,   tagged: 4,  ip: "10.24.200.1/24",desc: "PF quarantine VLAN",     active: true  },
  { id: 999,  name: "BLACKHOLE",   ports: 2,   tagged: 0,  ip: "—",            desc: "Disabled ports",         active: true  },
];

window.TAB_POE = {
  budget: 720,
  drawn: 428,
  reserved: 64,
  available: 228,
  perMember: [
    { idx: 1, drawn: 96,  budget: 180, ports: 9 },
    { idx: 2, drawn: 128, budget: 180, ports: 12 },
    { idx: 3, drawn: 88,  budget: 180, ports: 8 },
    { idx: 4, drawn: 116, budget: 180, ports: 9 },
  ],
  topConsumers: [
    { port: "2:14", device: "ARC-WAP-N4-23",  vendor: "Extreme Networks", watts: 25.1, cls: 4 },
    { port: "1:18", device: "ARC-WAP-N3-04",  vendor: "Extreme Networks", watts: 23.6, cls: 4 },
    { port: "4:33", device: "ARC-WAP-N1-09",  vendor: "Extreme Networks", watts: 22.2, cls: 4 },
    { port: "3:21", device: "ALTA-CAM-072",   vendor: "Avigilon",          watts: 18.4, cls: 4 },
    { port: "2:08", device: "ALTA-CAM-049",   vendor: "Avigilon",          watts: 17.9, cls: 4 },
    { port: "1:42", device: "VLN-PHONE-201",  vendor: "Polycom",           watts: 12.6, cls: 3 },
    { port: "4:11", device: "VLN-PHONE-117",  vendor: "Polycom",           watts: 12.4, cls: 3 },
    { port: "3:35", device: "ALTA-CAM-088",   vendor: "Avigilon",          watts: 11.8, cls: 3 },
    { port: "1:07", device: "ARC-WAP-N2-12",  vendor: "Extreme Networks", watts:  9.2, cls: 4 },
    { port: "2:31", device: "ALTA-CAM-051",   vendor: "Avigilon",          watts:  8.4, cls: 3 },
  ],
};

window.TAB_MACROS = [
  { k: "{$AGENT.NODATA.TIMEOUT}",       v: "30m",                ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$CPU.UTIL.MAX}",               v: "85",                 ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$MEM.UTIL.MAX}",               v: "90",                 ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$TEMP.MAX.CRIT}",              v: "78",                 ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$TEMP.MAX.WARN}",              v: "72",                 ctx: "ARC-MDF (override)",        sys: false },
  { k: "{$IF.ERRORS.WARN}",             v: "2",                  ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$IF.UTIL.MAX}",                v: "85",                 ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$POE.BUDGET.WARN}",            v: "80",                 ctx: "Template Net Extreme EXOS", sys: false },
  { k: "{$SNMP.COMMUNITY}",             v: "********",           ctx: "Template Net Generic SNMPv2", sys: true },
  { k: "{$SNMP.TIMEOUT}",               v: "5s",                 ctx: "Template Net Generic SNMPv2", sys: true },
  { k: "{$LLDP.NEIGHBOR.CHECK}",        v: "1",                  ctx: "Template Net Extreme EXOS", sys: false },
];

window.TAB_CLI = `* (Slot-1) ARC-MDF.1 # show stacking
Stack Topology is a Ring
Active Topology is a Ring
Node MAC Address    Slot  Stack State  Role     Flags
------------------  ----  -----------  -------  -------
*84:f1:b5:c2:00:a4   1     Active       Backup   --K--
 84:f1:b5:c2:01:08   2     Active       Master   --K--
 84:f1:b5:c2:02:1c   3     Active       Standby  --K--
 84:f1:b5:c2:04:2e   4     Active       Backup   --K--
* - Indicates this node
Flags:  (C) Candidate for this node's backup, (E) Master fail-over enabled,
        (K) Stacking license is enabled

* (Slot-1) ARC-MDF.2 # show power budget
PSU-1 (slot 1):  240 W   ok
PSU-2 (slot 1):  238 W   ok
PSU-1 (slot 2):  244 W   ok
PSU-2 (slot 2):  240 W   ok
PSU-1 (slot 3):  236 W   ok
PSU-2 (slot 3):    0 W   NOT PRESENT          << triggered alert
PSU-1 (slot 4):  232 W   ok
PSU-2 (slot 4):  230 W   ok
                 -------
Total budget:    720 W
Total drawn:     428 W   (59.4 %)

* (Slot-1) ARC-MDF.3 # _`;

window.TAB_TRIGGERS = [
  { sev: "disaster", expr: "last(/ARC-MDF/system.uptime)<10m and change()<0",     name: "{HOST.NAME}: device just restarted",   status: "enabled", deps: 3, fires24h: 0, history: _spark(11, 0, 0) },
  { sev: "high",     expr: "min(/ARC-MDF/icmpping[],5m)=0",                       name: "{HOST.NAME}: ICMP unreachable for 5m", status: "enabled", deps: 7, fires24h: 0, history: _spark(13, 0, 1) },
  { sev: "high",     expr: "change(/ARC-MDF/net.if.in.errors[ifIndex.{#SNMPINDEX}])>{$IF.ERRORS.WARN}", name: "Interface {#IFNAME}: high inbound error rate", status: "enabled", deps: 0, fires24h: 2, history: _spark(17, 2, 5) },
  { sev: "high",     expr: "last(/ARC-MDF/sensor.temp[m2])>{$TEMP.MAX.CRIT}",     name: "Stack member 2: temp above critical",  status: "enabled", deps: 1, fires24h: 0, history: _spark(19, 65, 8) },
  { sev: "warning",  expr: "avg(/ARC-MDF/sensor.temp[m3],10m)>{$TEMP.MAX.WARN}",  name: "Stack member 3: temp above warning",   status: "firing", deps: 0, fires24h: 3, history: _spark(23, 70, 6) },
  { sev: "warning",  expr: "max(/ARC-MDF/poe.budget.pct,10m)>{$POE.BUDGET.WARN}", name: "PoE budget utilization above 80%",     status: "enabled", deps: 0, fires24h: 1, history: _spark(29, 60, 14) },
  { sev: "warning",  expr: "count(/ARC-MDF/net.if.link[ifIndex.{#SNMPINDEX}],1h,\"<>1\")>4", name: "Interface {#IFNAME}: flapping (4+ events/h)", status: "firing", deps: 0, fires24h: 1, history: _spark(31, 1, 3) },
  { sev: "average",  expr: "last(/ARC-MDF/cpu.util[5m])>{$CPU.UTIL.MAX}",         name: "CPU utilization above 85%",            status: "enabled", deps: 0, fires24h: 0, history: _spark(37, 22, 6) },
  { sev: "average",  expr: "last(/ARC-MDF/vm.memory.util)>{$MEM.UTIL.MAX}",       name: "Memory utilization above 90%",         status: "enabled", deps: 0, fires24h: 0, history: _spark(41, 36, 4) },
  { sev: "info",     expr: "change(/ARC-MDF/extreme.cfg.hash)<>0",                name: "Running config changed",               status: "enabled", deps: 0, fires24h: 1, history: _spark(43, 0, 0) },
];

window.TAB_BACKUPS = [
  { ts: "2026-05-09 04:00:02", user: "auto (zbx-conf)", method: "SSH+SCP", size: "118.4 KB", lines: 4112, changed: 0,  hash: "9c4e…f30a", note: "Nightly scheduled backup" },
  { ts: "2026-05-08 14:18:55", user: "ksimmons@tcs",    method: "Web UI",  size: "118.4 KB", lines: 4112, changed: 2,  hash: "9c4e…f30a", note: "Added VLAN 100 untagged to 2:31" },
  { ts: "2026-05-08 04:00:01", user: "auto (zbx-conf)", method: "SSH+SCP", size: "118.3 KB", lines: 4110, changed: 0,  hash: "8b9d…2e74", note: "Nightly scheduled backup" },
  { ts: "2026-05-07 11:42:11", user: "tservice@tcs",    method: "SSH",     size: "118.3 KB", lines: 4110, changed: 5,  hash: "8b9d…2e74", note: "Updated uplink trunk config" },
  { ts: "2026-05-07 04:00:01", user: "auto (zbx-conf)", method: "SSH+SCP", size: "117.9 KB", lines: 4105, changed: 0,  hash: "73af…ec01", note: "Nightly scheduled backup" },
  { ts: "2026-05-06 09:11:48", user: "ksimmons@tcs",    method: "Web UI",  size: "117.9 KB", lines: 4105, changed: 1,  hash: "73af…ec01", note: "Updated SNMP location string" },
];

window.TAB_DIFF = [
  { type: "ctx",  ln: 1242, txt: "configure vlan FACULTY tag 20" },
  { type: "ctx",  ln: 1243, txt: "configure vlan FACULTY add ports 1:7,1:9,1:11 tagged" },
  { type: "del",  ln: 1244, txt: "configure vlan PRINTERS add ports 2:31 untagged" },
  { type: "add",  ln: 1244, txt: "configure vlan CAMERAS add ports 2:31 untagged" },
  { type: "ctx",  ln: 1245, txt: "configure vlan VOIP add ports 1:42,4:11 untagged" },
];

// ───────────────────────────────────────────────────────────────────
// 1. TOPOLOGY
// ───────────────────────────────────────────────────────────────────
const TabTopology = ({ host }) => {
  const T = window.TAB_TOPOLOGY;
  const stack = window.ARC_MDF_STACK;
  return (
    <div className="tab-pane">
      <div className="topo-layout">
        <div className="card topo-canvas-card">
          <div className="card-h">
            <h3>Stack &amp; uplink topology</h3>
            <SourceBadge src="ext" />
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">LLDP · 30s discovery</span>
          </div>
          <div className="topo-canvas">
            {/* Upstreams */}
            <div className="topo-tier topo-tier-up">
              <div className="topo-tier-label">CORE</div>
              <div className="topo-row">
                {T.upstreams.map(u => (
                  <div key={u.id} className="topo-node core">
                    <div className="n-id">{u.id}</div>
                    <div className="n-port">{u.port} · {u.type}</div>
                    <div className="n-util">
                      <span className="b"><i style={{ width: `${u.util}%`}} /></span>
                      <span className="pct">{u.util}%</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Trunk lines */}
            <svg className="topo-edges topo-edges-up" viewBox="0 0 600 40" preserveAspectRatio="none">
              <path d="M150,0 C150,20 300,20 300,40" />
              <path d="M450,0 C450,20 300,20 300,40" />
            </svg>

            {/* Stack */}
            <div className="topo-stack">
              <div className="topo-tier-label">STACK · {host.id}</div>
              <div className="topo-stack-rack">
                {stack.map((m, i) => {
                  const info = window.TAB_STACK_HEALTH[i];
                  return (
                    <div key={m.idx} className="topo-stack-member">
                      <div className="m-bezel">
                        <div className="m-led" />
                        <div className="m-id">M{m.idx}</div>
                        <div className="m-role">{info.role}</div>
                        <div className="m-ports">{m.upCount}↑ {m.downCount}↓</div>
                        <div className="m-bays">
                          {[0,1,2,3].map(b => <div key={b} className={"bay " + (b < 2 ? "lit" : "")} />)}
                        </div>
                        <div className="m-sfp">SFP+</div>
                      </div>
                      {i < stack.length - 1 && <div className="m-link" />}
                    </div>
                  );
                })}
                <div className="topo-stack-ring">
                  <svg viewBox="0 0 40 220" preserveAspectRatio="none">
                    <path d="M 8 12 C -8 60 -8 160 8 208" />
                    <path d="M 32 12 C 48 60 48 160 32 208" />
                    <circle cx="8" cy="12" r="3" />
                    <circle cx="32" cy="12" r="3" />
                    <circle cx="8" cy="208" r="3" />
                    <circle cx="32" cy="208" r="3" />
                  </svg>
                  <div className="ring-label">stack ring<br/>40 Gbps</div>
                </div>
              </div>
            </div>

            <svg className="topo-edges topo-edges-down" viewBox="0 0 800 40" preserveAspectRatio="none">
              <path d="M400,0 C400,20 100,20 100,40" />
              <path d="M400,0 C400,20 300,20 300,40" />
              <path d="M400,0 C400,20 500,20 500,40" />
              <path d="M400,0 C400,20 700,20 700,40" />
            </svg>

            {/* Downstreams */}
            <div className="topo-tier topo-tier-down">
              <div className="topo-tier-label">DOWNSTREAM IDF / EDGE</div>
              <div className="topo-row">
                {T.downstreams.map(d => (
                  <div key={d.id} className={"topo-node edge " + (d.status === "down" ? "down" : "")}>
                    <div className="n-id">{d.id}</div>
                    <div className="n-port">{d.port} · {d.type}</div>
                    <div className="n-util">
                      <span className="b"><i className={d.util > 50 ? "warn" : ""} style={{ width: `${Math.max(2, d.util)}%`}} /></span>
                      <span className="pct">{d.util}%</span>
                    </div>
                    {d.errors > 0 && <div className="n-err"><Icon name="alert" size={9}/> {d.errors} err/h</div>}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>LLDP neighbors</h3>
            <SourceBadge src="ext" />
            <div className="h-spacer" />
            <span className="h-meta">{T.lldp.length} learned</span>
          </div>
          <table className="link-tbl">
            <thead>
              <tr>
                <th style={{width: 56}}>Local</th>
                <th>Remote</th>
                <th style={{width: 56}}>R-Port</th>
                <th style={{width: 60}}>Age</th>
              </tr>
            </thead>
            <tbody>
              {T.lldp.map((l, i) => (
                <tr key={i}>
                  <td className="fg" style={{color:"var(--accent)"}}>{l.local}</td>
                  <td style={{whiteSpace: "normal", lineHeight: 1.35}}>
                    <div style={{color: "var(--fg)"}}>{l.remote}</div>
                    <div style={{color: "var(--muted)", fontSize: 10}}>{l.sysDesc}</div>
                  </td>
                  <td>{l.remPort}</td>
                  <td style={{color: "var(--muted)"}}>{l.macAge}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 2. STACK HEALTH
// ───────────────────────────────────────────────────────────────────
const HealthMetric = ({ label, val, unit, threshold, hist, color }) => {
  const isWarn = threshold && val >= threshold;
  return (
    <div className="hm-cell">
      <div className="hm-lbl">{label}</div>
      <div className={"hm-val" + (isWarn ? " warn" : "")}>{val}<span className="hm-unit">{unit}</span></div>
      <Sparkline data={hist} color={color || (isWarn ? "var(--warn)" : "var(--ok)")} width={120} height={22} threshold={threshold} />
    </div>
  );
};

// Merge live per-member snapshot data (window.STACK_MEMBERS) with the demo
// rows. Live values win when present; demo fields are kept as fallback so
// the card stays renderable while the template patch is still being rolled
// out. Returns one entry per slot the snapshot reported, or the full demo
// set if the snapshot is empty.
const _fmtUptime = (sec) => {
  if (sec == null || !isFinite(sec) || sec <= 0) return null;
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  return `${d}d ${String(h).padStart(2, "0")}h`;
};

const buildMemberRows = () => {
  const demo = window.TAB_STACK_HEALTH || [];
  const live = Array.isArray(window.STACK_MEMBERS) ? window.STACK_MEMBERS : [];
  if (live.length === 0) return demo.map(d => ({ ...d, _live: false }));

  const demoByIdx = Object.fromEntries(demo.map(d => [d.idx, d]));
  return live.map(m => {
    const d = demoByIdx[m.idx] || demo[0] || {};
    const liveUptime = _fmtUptime(m.uptime);
    // Fans: snapshot returns the actual fans grouped to this slot. The card
    // template only has two cells, so we take the first two; if the snapshot
    // reports fewer (e.g. only fan-status, no slot mapping), we backfill
    // from demo.
    const fans = Array.isArray(m.fans) ? m.fans : [];
    const psus = Array.isArray(m.psus) ? m.psus : [];
    const fanCells = [0, 1].map(i => {
      const f = fans[i];
      if (!f) return null;
      return { rpm: f.rpm || 0, ok: f.ok !== false };
    });
    const psuCells = [0, 1].map(i => {
      const p = psus[i];
      if (!p) return null;
      return { watts: p.watts || 0, status: p.status || 0, present: !!p.present, ok: !!p.ok };
    });
    return {
      ...d,
      idx:     m.idx,
      role:    m.role || d.role || "Member",
      cpu:     m.cpu  != null ? Math.round(m.cpu)  : d.cpu,
      cpu5:    m.cpu5 != null ? Math.round(m.cpu5) : d.cpu5,
      mem:     m.mem  != null ? Math.round(m.mem)  : d.mem,
      temp:    m.temp != null ? Math.round(m.temp) : d.temp,
      serial:  m.serial  || d.serial,
      version: m.version || d.version,
      uptime:  liveUptime || d.uptime,
      _fanCells: fanCells,
      _psuCells: psuCells,
      _live: (m.cpu != null || m.mem != null || m.temp != null
              || m.serial != null || m.version != null
              || fans.length > 0 || psus.length > 0)
    };
  });
};

const TabStackHealth = () => {
  const H = buildMemberRows();
  const anyLive = H.some(m => m._live);
  return (
    <div className="tab-pane">
      <div className="card-h-bar">
        <span className="h-title">Stack member health · last 24h</span>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">
          {anyLive ? "SNMP · live from Zabbix" : "demo — apply per-member-health template patch"}
        </span>
      </div>
      <div className="health-grid">
        {H.map(m => (
          <div key={m.idx} className={"card health-card " + (m.warn ? "warn" : "")}>
            <div className="hc-head">
              <div className="hc-id-block">
                <div className="hc-id">MEMBER {m.idx}</div>
                <div className={"hc-role " + String(m.role || "").toLowerCase()}>{m.role}</div>
              </div>
              <div className="hc-side">
                <div className="kv"><span>Serial</span><b>{m.serial}</b></div>
                <div className="kv"><span>EXOS</span><b>{m.version}</b></div>
                <div className="kv"><span>Uptime</span><b>{m.uptime}</b></div>
              </div>
            </div>
            <div className="hm-grid">
              <HealthMetric label="CPU 1m"  val={m.cpu}  unit="%"  threshold={85} hist={_spark(m.idx * 11, m.cpu  || 0, 6)} color="var(--info)" />
              <HealthMetric label="CPU 5m"  val={m.cpu5} unit="%"  threshold={75} hist={_spark(m.idx * 17, m.cpu5 || 0, 4)} color="var(--info)" />
              <HealthMetric label="Memory"  val={m.mem}  unit="%"  threshold={90} hist={_spark(m.idx * 23, m.mem  || 0, 3)} color="var(--zbx)" />
              <HealthMetric label="Temp"    val={m.temp} unit="°C" threshold={72} hist={_spark(m.idx * 29, m.temp || 0, 5)} color="var(--pf)" />
            </div>
            <div className="hc-foot">
              {[0, 1].map(i => {
                const live = m._fanCells && m._fanCells[i];
                const demoRpm = i === 0 ? m.fan1 : m.fan2;
                const rpm = live ? live.rpm : demoRpm;
                const failed = live ? !live.ok : false;
                return (
                  <div key={`fan${i}`} className="hcf-cell">
                    <span className="lbl">FAN {i + 1}</span>
                    <span className={"val " + (failed ? "err" : (rpm > 6000 ? "warn" : ""))}>
                      {rpm > 0 ? `${rpm} RPM` : "—"}
                    </span>
                  </div>
                );
              })}
              {[0, 1].map(i => {
                const live = m._psuCells && m._psuCells[i];
                const demoWatts = i === 0 ? m.psu1 : m.psu2;
                if (live) {
                  const absent = !live.present;
                  return (
                    <div key={`psu${i}`} className="hcf-cell">
                      <span className="lbl">PSU {i + 1}</span>
                      <span className={"val " + (absent ? "err" : (live.ok ? "" : "warn"))}>
                        {absent ? "absent" : (live.watts > 0 ? `${live.watts} W` : (live.ok ? "ok" : "fault"))}
                      </span>
                    </div>
                  );
                }
                return (
                  <div key={`psu${i}`} className="hcf-cell">
                    <span className="lbl">PSU {i + 1}</span>
                    <span className={"val " + (demoWatts === 0 ? "err" : "")}>
                      {demoWatts === 0 ? "absent" : `${demoWatts} W`}
                    </span>
                  </div>
                );
              })}
            </div>
            {m.warn && (
              <div className="hc-alert">
                <Icon name="alert" size={11} /> {m.warn}
              </div>
            )}
          </div>
        ))}
      </div>

      <div className="card" style={{marginTop: 14}}>
        <div className="card-h">
          <h3>Per-member temperature · 24h</h3>
          <SourceBadge src="zbx" />
          <div className="h-spacer" />
          <span className="h-meta">{`>72°C `}warning · {`>78°C `}critical</span>
        </div>
        <div className="thermal-strip">
          {H.map(m => {
            const hist = _spark(m.idx * 53, m.temp || 0, 6, 48);
            return (
              <div key={m.idx} className="ts-row">
                <div className="ts-lbl">M{m.idx}</div>
                <div className="ts-cells">
                  {hist.map((v, i) => {
                    const cls = v >= 78 ? "crit" : v >= 72 ? "warn" : "ok";
                    return <i key={i} className={cls} title={`${v}°C`} />;
                  })}
                </div>
                <div className="ts-cur">{m.temp != null ? `${m.temp}°C` : "—"}</div>
              </div>
            );
          })}
          <div className="ts-axis">
            <span>−24h</span><span>−18h</span><span>−12h</span><span>−6h</span><span>now</span>
          </div>
        </div>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 3. VLAN
// ───────────────────────────────────────────────────────────────────
const TabVlan = () => {
  const V = window.TAB_VLANS;
  const [sel, setSel] = useStateTAB(20);
  return (
    <div className="tab-pane">
      <div className="vlan-layout">
        <div className="card">
          <div className="card-h">
            <h3>VLAN table</h3>
            <SourceBadge src="ext" />
            <div className="h-spacer" />
            <span className="h-meta">{V.length} VLANs · 8 user · 4 system</span>
            <span className="h-link">+ Add VLAN</span>
          </div>
          <table className="vlan-tbl">
            <thead>
              <tr>
                <th style={{width: 50}}>VID</th>
                <th>Name</th>
                <th style={{width: 80}}>Untagged</th>
                <th style={{width: 70}}>Tagged</th>
                <th style={{width: 140}}>IP</th>
                <th style={{width: 60}}>State</th>
              </tr>
            </thead>
            <tbody>
              {V.map(v => (
                <tr key={v.id} className={sel === v.id ? "sel" : ""} onClick={() => setSel(v.id)}>
                  <td className="mono fg" style={{color:"var(--accent)"}}>{v.id}</td>
                  <td>
                    <div className="vname">{v.name}</div>
                    <div className="vdesc">{v.desc}</div>
                  </td>
                  <td className="mono">
                    <span className="port-pill">{v.ports}</span>
                  </td>
                  <td className="mono">
                    <span className="port-pill tag">{v.tagged}</span>
                  </td>
                  <td className="mono">{v.ip}</td>
                  <td>{v.active ? <span className="state-dot ok" title="active" /> : <span className="state-dot off" title="inactive" />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div style={{display:"flex", flexDirection:"column", gap: 14, minWidth: 0}}>
          <div className="card">
            <div className="card-h">
              <h3>VLAN {sel} · port membership</h3>
              <SourceBadge src="ext" />
              <div className="h-spacer" />
              <span className="h-meta">untagged · 1 member</span>
            </div>
            <div className="vlan-portmap">
              {window.ARC_MDF_STACK.map(m => (
                <div key={m.idx} className="vp-row">
                  <span className="vp-id">M{m.idx}</span>
                  <div className="vp-grid">
                    {m.ports.map(p => {
                      let cls = "u-absent";
                      if (p.state !== "absent") {
                        const inV = sel === 20 ? (p.n % 3 === 0 && p.n <= 38) : sel === 30 ? (p.n % 4 === 1) : sel === 50 ? (p.n % 7 === 0) : (p.n % 11 === 0);
                        cls = inV ? "u-in" : "u-out";
                      }
                      return <i key={p.n} className={cls} title={`${m.idx}:${p.n}`} />;
                    })}
                  </div>
                </div>
              ))}
              <div className="vp-legend">
                <span><i className="u-in" /> Untagged in VLAN {sel}</span>
                <span><i className="u-out" /> Other VLAN</span>
                <span><i className="u-absent" /> Not present</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 4. PoE BUDGET
// ───────────────────────────────────────────────────────────────────
const TabPoe = () => {
  const P = window.TAB_POE;
  const pctTotal = Math.round((P.drawn / P.budget) * 100);
  return (
    <div className="tab-pane">
      <div className="poe-top">
        <div className="card poe-headline">
          <div className="poe-hl-left">
            <Ring value={P.drawn} max={P.budget} size={140} color="var(--warn)" label={`${P.drawn} W`} sub={`of ${P.budget} W budget`} threshold={P.budget * 0.85} />
          </div>
          <div className="poe-hl-stats">
            <div className="phs">
              <span className="lbl">Drawn</span>
              <span className="v warn">{P.drawn} W</span>
              <span className="sub">{pctTotal}% utilised</span>
            </div>
            <div className="phs">
              <span className="lbl">Reserved (LLDP MED)</span>
              <span className="v">{P.reserved} W</span>
              <span className="sub">8 ports negotiated</span>
            </div>
            <div className="phs">
              <span className="lbl">Available</span>
              <span className="v ok">{P.available} W</span>
              <span className="sub">enough for ~22 class-4 APs</span>
            </div>
            <div className="phs">
              <span className="lbl">PSU redundancy</span>
              <span className="v err">N+0</span>
              <span className="sub">PSU2 slot-3 absent</span>
            </div>
          </div>
        </div>

        <div className="card poe-perm">
          <div className="card-h">
            <h3>Per-member draw</h3>
            <SourceBadge src="zbx" />
          </div>
          <div className="poe-perm-body">
            {P.perMember.map(m => {
              const pct = Math.round((m.drawn / m.budget) * 100);
              return (
                <div key={m.idx} className="ppm-row">
                  <div className="ppm-id">MEMBER {m.idx}</div>
                  <div className="ppm-bar">
                    <i className={pct > 80 ? "warn" : ""} style={{ width: `${pct}%` }} />
                    <span className="ppm-val">{m.drawn} / {m.budget} W</span>
                  </div>
                  <div className="ppm-ports">{m.ports} ports</div>
                  <div className="ppm-pct">{pct}%</div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      <div className="card" style={{marginTop: 14}}>
        <div className="card-h">
          <h3>Top PoE consumers</h3>
          <SourceBadge src="zbx" />
          <SourceBadge src="pf" />
          <div className="h-spacer" />
          <span className="h-meta">cross-referenced PacketFence · sorted by W draw</span>
        </div>
        <table className="link-tbl poe-tbl">
          <thead>
            <tr>
              <th style={{width: 60}}>Port</th>
              <th>Device</th>
              <th>Vendor</th>
              <th style={{width: 70}}>Class</th>
              <th style={{width: 160}}>Draw</th>
              <th style={{width: 80, textAlign:"right"}}>Watts</th>
            </tr>
          </thead>
          <tbody>
            {P.topConsumers.map((c, i) => {
              const pct = Math.round((c.watts / 30) * 100);
              return (
                <tr key={i}>
                  <td className="fg" style={{color: "var(--accent)"}}>{c.port}</td>
                  <td style={{color: "var(--fg)"}}>{c.device}</td>
                  <td>{c.vendor}</td>
                  <td><span className={"poe-cls cls-" + c.cls}>Class {c.cls}</span></td>
                  <td>
                    <span className="util-bar"><i style={{ width: `${pct}%`, background: c.cls === 4 ? "var(--warn)" : "var(--ok)" }} /></span>
                  </td>
                  <td style={{textAlign:"right"}}>{c.watts.toFixed(1)} W</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 5. MACROS · CLI
// ───────────────────────────────────────────────────────────────────
const TabMacros = ({ host }) => {
  const M = window.TAB_MACROS;
  return (
    <div className="tab-pane">
      <div className="macro-layout">
        <div className="card">
          <div className="card-h">
            <h3>User macros</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">{M.length} resolved · inherited from template + host override</span>
            <span className="h-link">+ Add macro</span>
          </div>
          <table className="macro-tbl">
            <thead>
              <tr>
                <th>Macro</th>
                <th style={{width: 100}}>Value</th>
                <th>Context (effective)</th>
              </tr>
            </thead>
            <tbody>
              {M.map((m, i) => (
                <tr key={i} className={m.sys ? "sys" : ""}>
                  <td className="mono mac-k">{m.k}</td>
                  <td className="mono mac-v">{m.v}</td>
                  <td>
                    <span className={"ctx-pill " + (m.ctx.includes("override") ? "ovr" : "tpl")}>
                      {m.ctx.includes("override") ? "host" : "template"}
                    </span>
                    {m.ctx}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>CLI capture · ssh {host.id}</h3>
            <SourceBadge src="ext" />
            <div className="h-spacer" />
            <span className="h-meta">read-only session · expires 4m 12s</span>
            <span className="h-link">Reconnect</span>
          </div>
          <div className="cli-pane">
            <div className="cli-tabs">
              <span className="ct active">show stacking</span>
              <span className="ct">show ports info</span>
              <span className="ct">show power budget</span>
              <span className="ct">show fdb</span>
              <span className="ct">show log</span>
            </div>
            <pre className="cli-term">{window.TAB_CLI}<span className="cur" /></pre>
            <div className="cli-input">
              <span className="prompt">* (Slot-1) ARC-MDF.4 #</span>
              <input placeholder="run command…" />
              <span className="hint">read-only · Ctrl-C to abort</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 6. TRIGGERS
// ───────────────────────────────────────────────────────────────────
const TabTriggers = () => {
  const T = window.TAB_TRIGGERS;
  const counts = {
    firing: T.filter(t => t.status === "firing").length,
    enabled: T.filter(t => t.status === "enabled").length,
  };
  return (
    <div className="tab-pane">
      <div className="card-h-bar">
        <span className="h-title">Triggers</span>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <div className="trig-filter">
          <span className="tf active">All <b>{T.length}</b></span>
          <span className="tf warn">Firing <b>{counts.firing}</b></span>
          <span className="tf">Enabled <b>{counts.enabled}</b></span>
          <span className="tf">Disabled <b>0</b></span>
        </div>
        <span className="h-meta">Template Net Extreme EXOS</span>
      </div>

      <div className="card">
        <table className="trig-tbl">
          <thead>
            <tr>
              <th style={{width: 90}}>Severity</th>
              <th>Name &amp; expression</th>
              <th style={{width: 120}}>1h history</th>
              <th style={{width: 75}}>Fires 24h</th>
              <th style={{width: 60}}>Deps</th>
              <th style={{width: 80}}>Status</th>
            </tr>
          </thead>
          <tbody>
            {T.map((t, i) => (
              <tr key={i} className={t.status === "firing" ? "firing" : ""}>
                <td><Sev level={t.sev} /></td>
                <td>
                  <div className="trig-name">{t.name}</div>
                  <code className="trig-expr">{t.expr}</code>
                </td>
                <td><Sparkline data={t.history} color={t.status === "firing" ? "var(--warn)" : "var(--muted-2)"} width={110} height={24} /></td>
                <td className="mono" style={{textAlign:"center", color: t.fires24h > 0 ? "var(--warn)" : "var(--muted)"}}>{t.fires24h}</td>
                <td className="mono" style={{textAlign:"center", color: t.deps > 0 ? "var(--fg-2)" : "var(--muted)"}}>{t.deps}</td>
                <td>
                  <span className={"trig-status " + t.status}>{t.status.toUpperCase()}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// 7. CONFIG BACKUPS
// ───────────────────────────────────────────────────────────────────
const TabBackups = () => {
  const B = window.TAB_BACKUPS;
  const D = window.TAB_DIFF;
  const [sel, setSel] = useStateTAB(1);
  return (
    <div className="tab-pane">
      <div className="backup-layout">
        <div className="card">
          <div className="card-h">
            <h3>Configuration backups</h3>
            <SourceBadge src="ext" />
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">retention 90d · last 6 of 312 shown</span>
            <span className="h-link">Run backup now</span>
          </div>
          <table className="backup-tbl">
            <thead>
              <tr>
                <th style={{width: 22}}></th>
                <th style={{width: 150}}>Timestamp</th>
                <th>User</th>
                <th style={{width: 70}}>Method</th>
                <th style={{width: 70}}>Lines</th>
                <th style={{width: 70}}>Δ</th>
                <th style={{width: 90}}>Hash</th>
              </tr>
            </thead>
            <tbody>
              {B.map((b, i) => (
                <tr key={i} className={sel === i ? "sel" : ""} onClick={() => setSel(i)}>
                  <td><span className={"bk-dot " + (b.user.startsWith("auto") ? "auto" : "human")} /></td>
                  <td className="mono">{b.ts}</td>
                  <td>
                    <div>{b.user}</div>
                    <div className="bk-note">{b.note}</div>
                  </td>
                  <td>{b.method}</td>
                  <td className="mono" style={{textAlign:"right"}}>{b.lines}</td>
                  <td className="mono" style={{textAlign:"right", color: b.changed > 0 ? "var(--warn)" : "var(--muted)"}}>
                    {b.changed > 0 ? `+${b.changed}` : "—"}
                  </td>
                  <td className="mono" style={{color: "var(--muted)"}}>{b.hash}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Diff · {B[sel].hash}  →  {B[Math.max(0, sel - 1)].hash}</h3>
            <SourceBadge src="ext" />
            <div className="h-spacer" />
            <span className="h-meta">{B[sel].changed || 0} changed line{B[sel].changed === 1 ? "" : "s"} · context ±2</span>
            <span className="h-link">Restore this revision</span>
          </div>
          <div className="diff-pane">
            <div className="diff-meta">
              <div className="dm-col">
                <div className="dm-lbl">FROM</div>
                <div className="dm-ts">{B[sel].ts}</div>
                <div className="dm-by">{B[sel].user}</div>
              </div>
              <div className="dm-arrow">→</div>
              <div className="dm-col">
                <div className="dm-lbl">TO</div>
                <div className="dm-ts">{B[Math.max(0, sel - 1)].ts}</div>
                <div className="dm-by">{B[Math.max(0, sel - 1)].user}</div>
              </div>
              <div className="dm-spacer" />
              <div className="dm-stat add">+{D.filter(d => d.type === "add").length}</div>
              <div className="dm-stat del">−{D.filter(d => d.type === "del").length}</div>
            </div>
            <pre className="diff-body">
              {D.map((d, i) => {
                const pre = d.type === "add" ? "+" : d.type === "del" ? "−" : " ";
                return (
                  <div key={i} className={"diff-line " + d.type}>
                    <span className="dl-ln">{d.ln}</span>
                    <span className="dl-pre">{pre}</span>
                    <span className="dl-tx">{d.txt || "\u00a0"}</span>
                  </div>
                );
              })}
            </pre>
          </div>
        </div>
      </div>
    </div>
  );
};

// ───────────────────────────────────────────────────────────────────
// Tab definitions table — exported
// ───────────────────────────────────────────────────────────────────
window.SWITCH_TABS = [
  { id: "ports",    label: "Port Status",   badge: null },
  { id: "topo",     label: "Topology",      badge: null },
  { id: "health",   label: "Stack Health",  badge: null },
  { id: "vlan",     label: "VLAN",          badge: null },
  { id: "poe",      label: "PoE Budget",    badge: null },
  { id: "macros",   label: "Macros · CLI",  badge: null },
  { id: "triggers", label: "Triggers",      badge: { v: 3, kind: "warn" } },
  { id: "backups",  label: "Config Backups",badge: null },
];

window.TabTopology    = TabTopology;
window.TabStackHealth = TabStackHealth;
window.TabVlan        = TabVlan;
window.TabPoe         = TabPoe;
window.TabMacros      = TabMacros;
window.TabTriggers    = TabTriggers;
window.TabBackups     = TabBackups;
