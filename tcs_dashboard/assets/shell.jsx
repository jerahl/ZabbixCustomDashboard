// Main app shell — sidebar now lives in global-nav.jsx (unified across all pages)
const Sidebar = ({ tab, setTab }) => <GlobalSidebar active="wireless" />;

const Topbar = ({ onCmdK, activeAp }) => (
  <div className="topbar">
    <div className="icon-btn" title="Back"><Icon name="back" /></div>
    <div className="crumb">
      <span>Wireless APs</span>
      <span className="sep">/</span>
      <span>{activeAp ? activeAp.site : "Bryant High School"}</span>
      <span className="sep">/</span>
      <span>{activeAp ? activeAp.floor : "1st Floor"}</span>
      <span className="sep">/</span>
      <span className="seg">{activeAp ? activeAp.id : "BHS-56-Hallway"}</span>
    </div>
    <div className="spacer" />
    <div className="search" onClick={onCmdK}>
      <Icon name="search" />
      <input placeholder="Find host, MAC, user, IP…" readOnly />
      <kbd>⌘K</kbd>
    </div>
    <div className="icon-btn" title="Refresh"><Icon name="refresh" /></div>
    <div className="icon-btn" title="More"><Icon name="more" /></div>
  </div>
);

const PageHeader = ({ timeRange, setTimeRange, host }) => (
  <div className="page-header">
    <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
    <div style={{ flex: 1 }}>
      <div className="host-title">
        <h1>{host.host}</h1>
        <span className="ip">{host.ip}</span>
        <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>{host.model || "AP_305C"}</span>
      </div>
      <div className="host-meta">
        <span className="pill"><span className="dot" style={{ background: host.apStatus === "down" ? "var(--err)" : host.apStatus === "warn" ? "var(--warn)" : "var(--ok)" }} /> {host.apStatus === "down" ? "Unreachable" : host.apStatus === "warn" ? "Degraded" : "Connected"}</span>
        <span className="pill"><span className="lbl">Active since</span> <span className="v">{fmtUptime(host.uptime)}</span></span>
        <span className="pill"><span className="lbl">Site</span> <span>{host.site || "—"}{host.floor ? ` · ${host.floor}` : ""}</span></span>
        <span className="pill"><span className="lbl">Clients</span> <span className="v">{(host.clients ?? 0).toLocaleString()}</span></span>
        <span className="pill"><span className="lbl">Zabbix Host ID</span> <span className="v">{host.hostid || "—"}</span></span>
        {host.proxy && <span className="pill"><span className="lbl">Polled via</span> <span>{host.proxy}</span></span>}
      </div>
    </div>
    <div className="timerange">
      <Icon name="calendar" />
      <span className="range-val">{timeRange}</span>
      <Icon name="chevron" />
    </div>
  </div>
);

const Tabs = ({ tab, setTab }) => {
  // Re-read globals on every render so the badges follow live refreshes
  // dispatched by data-bridge.jsx.
  const clientCount  = Array.isArray(window.PF_CLIENTS) ? window.PF_CLIENTS.length : 0;
  const wiredCount   = Array.isArray(window.WIRED_PORTS) ? window.WIRED_PORTS.length : 0;
  const ssidCount    = Array.isArray(window.SSIDS) ? window.SSIDS.length : 0;
  const eventCount   = Array.isArray(window.ZBX_EVENTS) ? window.ZBX_EVENTS.filter(e => e && e.value === 1).length : 0;
  const A            = window.ALERTS_DETAIL || {};
  const triggerCount = Array.isArray(A.activeTriggers) ? A.activeTriggers.length : 0;

  const tabs = [
    ["overview", "Overview",      null,                         null],
    ["wireless", "Wireless",      ssidCount    > 0 ? ssidCount    : null, null],
    ["wired",    "Wired",         wiredCount   > 0 ? wiredCount   : null, null],
    ["clients",  "Clients",       clientCount  > 0 ? clientCount  : null, null],
    ["events",   "Events",        eventCount   > 0 ? eventCount   : null, eventCount   > 0 ? "warn" : null],
    ["alerts",   "Alerts",        triggerCount > 0 ? triggerCount : null, triggerCount > 0 ? "err"  : null],
    ["graphs",   "Graphs",        null, null],
    ["latest",   "Latest Data",   null, null],
    ["config",   "Configuration", null, null],
  ];
  return (
    <div className="tabs">
      {tabs.map(([k, l, b, tone]) => (
        <div key={k} className={`tab ${tab === k ? "active" : ""}`} onClick={() => setTab(k)}>
          {l}
          {b !== null && b !== undefined && <span className={`badge${tone ? " "+tone : ""}`}>{b}</span>}
        </div>
      ))}
    </div>
  );
};

// Format an uptime in seconds (from Zabbix system.uptime) as "Nd HHh MMm".
const fmtUptime = (s) => {
  s = Number(s) || 0;
  if (s <= 0) return "—";
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d > 0) return `${d}d ${String(h).padStart(2,"0")}h ${String(m).padStart(2,"0")}m`;
  if (h > 0) return `${h}h ${String(m).padStart(2,"0")}m`;
  return `${m}m`;
};

const DeviceSidecar = ({ host }) => {
  // host.available: 1 = up, 2 = down, anything else (0/null) = unknown.
  // Prefer the explicit apStatus the parent threads in from AP_SITES (which
  // already folds in trigger severity); fall back to availability.
  const state = host.apStatus === "down" ? "down"
              : host.apStatus === "warn" ? "warn"
              : host.available === 1 ? "ok"
              : host.available === 2 ? "down"
              : "idle";
  const stateLabel = state === "ok"   ? "Connected"
                   : state === "warn" ? "Degraded"
                   : state === "down" ? "Unreachable"
                   : "Unknown";
  const stateColor = state === "ok"   ? "var(--ok)"
                   : state === "warn" ? "var(--warn)"
                   : state === "down" ? "var(--err)"
                   : "var(--muted)";

  const templates = Array.isArray(host.templates) ? host.templates : [];
  const groups    = Array.isArray(host.groups)    ? host.groups    : [];
  const siteLine  = [host.site, host.floor].filter(Boolean).join(" · ");

  return (
    <div className="card device-card-h">
      <div className="dev-h-img">
        <svg width="56" height="56" viewBox="0 0 60 60">
          <ellipse cx="30" cy="46" rx="22" ry="4" fill="rgba(0,0,0,0.3)" />
          <rect x="6" y="22" width="48" height="20" rx="10" fill="#e8ecf4" />
          <rect x="6" y="22" width="48" height="6" rx="10" fill="#f4f7fc" />
          <circle cx="30" cy="32" r="3" fill="#181f2c" />
          <circle cx="30" cy="32" r="1" fill={stateColor} />
        </svg>
      </div>

      <div className="dev-h-id">
        <div className="device-name">{host.host || "—"}</div>
        <div className="status-line">
          <StatusDot state={state} />
          <span style={{ color: stateColor }}>{stateLabel}</span>
          <span className="muted" style={{ marginLeft: 6 }}>· uptime {fmtUptime(host.uptime)}</span>
        </div>
        <div className="dev-h-sub mono">
          {host.ip || "—"}{host.model ? ` · ${host.model}` : ""}
        </div>
      </div>

      <div className="dev-h-block">
        <div className="label">Location</div>
        <div className="v">
          {siteLine || "—"}
          {groups.length > 0 && (
            <div className="muted" style={{ fontSize: 11, marginTop: 2 }}>
              {groups.slice(0, 2).join(" · ")}
            </div>
          )}
        </div>
      </div>

      <div className="dev-h-block">
        <div className="label">Clients</div>
        <div
          className="v"
          style={{
            fontFamily: "var(--mono)", fontSize: 18, fontWeight: 600,
            color: host.loadLevel === "high" ? "var(--err)"
                 : host.loadLevel === "warn" ? "var(--warn)"
                 : "var(--fg)",
            display: "flex", alignItems: "center", gap: 6
          }}
          title={
            host.loadLevel === "high" ? "HIGH client load · over 50 clients"
            : host.loadLevel === "warn" ? "Elevated client load · over 35 clients"
            : null
          }
        >
          {(host.clients ?? 0).toLocaleString()}
          {host.loadLevel === "high" && (
            <span className="role-tag guest" style={{ fontSize: 9, padding: "0 6px" }}>HIGH</span>
          )}
          {host.loadLevel === "warn" && (
            <span className="role-tag av"    style={{ fontSize: 9, padding: "0 6px" }}>WARN</span>
          )}
        </div>
      </div>

      <div className="dev-h-block dev-h-templates">
        <div className="label">Zabbix Templates</div>
        <div className="v">
          {templates.length === 0 ? (
            <span className="muted">none</span>
          ) : (
            templates.slice(0, 4).map((t, i) => (
              <span key={i} className="tpl-chip">{t}</span>
            ))
          )}
        </div>
      </div>

      <div className="dev-h-actions">
        <button className="btn primary"><Icon name="refresh" size={12} /> Reboot</button>
        <button className="btn"><Icon name="external" size={12} /> SSH</button>
        <button className="btn ghost"><Icon name="more" size={12} /></button>
      </div>
    </div>
  );
};

// ───────── AP Host Navigator (left rail) ─────────
const APNavigator = ({ activeId, onSelect, query, setQuery }) => {
  // Start with every site collapsed except the one containing the active
  // AP. Search expands all matched sections regardless (handled below).
  const [sites, setSites] = React.useState(() =>
    (window.AP_SITES || []).map(s => ({
      ...s,
      expanded: Array.isArray(s.aps) && s.aps.some(a => a.id === activeId)
    }))
  );
  const toggle = (idx) => {
    setSites(sites.map((s, i) => i === idx ? { ...s, expanded: !s.expanded } : s));
  };
  const q = (query || "").trim().toLowerCase();
  const totalAps = window.AP_SITES.reduce((n, s) => n + s.aps.length, 0);
  const totalClients = window.AP_SITES.reduce((n, s) => n + s.aps.reduce((m, a) => m + a.clients, 0), 0);
  const totalProb = window.AP_SITES.reduce((n, s) => n + s.problems, 0);

  return (
    <div className="card ap-nav-card">
      <div className="card-h">
        <h3>AP Navigator</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">{totalAps} APs</span>
      </div>
      <div className="ap-nav-search">
        <Icon name="search" size={12} />
        <input
          placeholder="Filter by id, ip, site…"
          value={query || ""}
          onChange={(e) => setQuery(e.target.value)}
          spellCheck={false}
        />
        {query ? <span className="ap-nav-clear" onClick={() => setQuery("")}>×</span> : null}
      </div>
      <div className="ap-nav-summary">
        <span><b>{totalClients.toLocaleString()}</b> clients</span>
        <span className="dot-sep">·</span>
        <span><b style={{color:"var(--ok)"}}>{totalAps - totalProb}</b> healthy</span>
        <span className="dot-sep">·</span>
        <span><b style={{color:"var(--warn)"}}>{totalProb}</b> with triggers</span>
      </div>
      <div className="ap-nav">
        {sites.map((site, i) => {
          const matchedAps = q
            ? site.aps.filter(a =>
                a.id.toLowerCase().includes(q) ||
                a.ip.toLowerCase().includes(q) ||
                a.floor.toLowerCase().includes(q) ||
                site.name.toLowerCase().includes(q))
            : site.aps;
          if (q && matchedAps.length === 0) return null;
          const expanded = q ? true : site.expanded;
          return (
            <div className="ap-nav-section" key={site.id}>
              <div
                className={"ap-nav-site" + (expanded ? "" : " collapsed")}
                onClick={() => !q && toggle(i)}
              >
                <svg className="caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                  <path d="m4 6 4 4 4-4" />
                </svg>
                <span className="site-name">{site.name}</span>
                <span className="site-count">{matchedAps.length}</span>
                {site.problems > 0 && <span className="site-prob">{site.problems}</span>}
                {site.overloaded > 0 && (
                  <span className="site-load" title={`${site.overloaded} AP${site.overloaded === 1 ? "" : "s"} with high client load`}>
                    {site.overloaded}↑
                  </span>
                )}
              </div>
              <div className={"ap-nav-children" + (expanded ? "" : " hidden")}>
                {matchedAps.map(ap => {
                  const dotColor = ap.status === "ok"   ? "var(--ok)"
                                 : ap.status === "warn" ? "var(--warn)"
                                 : "var(--err)";
                  const loadColor = ap.loadLevel === "high" ? "var(--err)"
                                  : ap.loadLevel === "warn" ? "var(--warn)"
                                  : "var(--fg)";
                  const loadTitle = ap.loadLevel === "high" ? "Client load HIGH (> 50 clients)"
                                  : ap.loadLevel === "warn" ? "Client load WARN (> 35 clients)"
                                  : `${ap.clients} clients`;
                  return (
                    <div
                      key={ap.id}
                      className={"ap-nav-host" + (ap.id === activeId ? " active" : "")}
                      onClick={() => onSelect(ap)}
                      title={`${ap.id} · ${ap.ip} · ${ap.model} · ${loadTitle}`}
                    >
                      <span className="ap-led" style={{ background: dotColor, boxShadow: ap.status === "ok" ? `0 0 4px ${dotColor}` : "none" }} />
                      <div className="ap-meta-col">
                        <div className="ap-id">{ap.id}</div>
                        <div className="ap-sub">{ap.floor} · {ap.model}</div>
                      </div>
                      <div className="ap-cli" title={loadTitle}>
                        <div className="n" style={{ color: loadColor, fontWeight: ap.loadLevel === "ok" ? 500 : 700 }}>{ap.clients}</div>
                        <div className="u">cli</div>
                      </div>
                      {ap.problems > 0 && <span className="ap-prob">{ap.problems}</span>}
                    </div>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

const CommandPalette = ({ onClose }) => {
  const items = [
    { cat: "Host", label: "BHS-56-Hallway", sub: "172.16.97.59" },
    { cat: "Host", label: "BHS-57-Library", sub: "172.16.97.60" },
    { cat: "Host", label: "CHS-12-Cafeteria", sub: "172.17.4.18" },
    { cat: "Action", label: "Reboot AP", sub: "Zabbix · executescript" },
    { cat: "Client", label: "MAC A4:83:E7:91:2C:14", sub: "j.harris@tcs · ChromeOS" },
    { cat: "Client", label: "MAC F4:5C:89:0B:32:71", sub: "Quarantined · VLAN 666" },
    { cat: "User", label: "k.davis@tcs", sub: "Faculty · 1 active session" },
    { cat: "Site", label: "Bryant High School / 1st Floor", sub: "47 APs" },
  ];
  const [q, setQ] = React.useState("");
  const [sel, setSel] = React.useState(0);
  const filtered = items.filter(i => i.label.toLowerCase().includes(q.toLowerCase()) || i.sub.toLowerCase().includes(q.toLowerCase()));
  React.useEffect(() => {
    const onKey = (e) => {
      if (e.key === "Escape") onClose();
      if (e.key === "ArrowDown") { setSel(s => Math.min(filtered.length - 1, s + 1)); e.preventDefault(); }
      if (e.key === "ArrowUp")   { setSel(s => Math.max(0, s - 1)); e.preventDefault(); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [filtered.length, onClose]);
  return (
    <div className="scrim" onClick={onClose}>
      <div className="palette" onClick={e => e.stopPropagation()}>
        <input className="palette-input" autoFocus placeholder="Search hosts, clients, users, MACs, IPs…" value={q} onChange={e => { setQ(e.target.value); setSel(0); }} />
        <div className="palette-list">
          {filtered.map((it, i) => (
            <div key={i} className={`palette-item ${i === sel ? "active" : ""}`} onMouseEnter={() => setSel(i)}>
              <Icon name={it.cat === "Host" ? "ap" : it.cat === "Client" ? "clients" : it.cat === "User" ? "user" : it.cat === "Site" ? "map" : "events"} size={14} />
              <div>
                <div>{it.label}</div>
                <div className="pi-mac">{it.sub}</div>
              </div>
              <span className="pi-cat">{it.cat}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

// ───────── Tweaks ─────────
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#d92929",
  "showSourceBadges": true,
  "showFloorplan": true,
  "showSidecar": true,
  "showApNav": true,
  "selectedAp": "BHS-56-Hallway",
  "fontMono": "JetBrains Mono"
}/*EDITMODE-END*/;

const Tweaks = ({ t, setTweak }) => (
  <TweaksPanel title="Tweaks">
    <TweakSection title="Layout">
      <TweakRadio label="Density" value={t.density} options={[
        { value: "spacious", label: "Spacious" },
        { value: "balanced", label: "Balanced" },
        { value: "dense", label: "Dense" }
      ]} onChange={v => setTweak("density", v)} />
      <TweakToggle label="Show AP host navigator (left rail)" value={t.showApNav} onChange={v => setTweak("showApNav", v)} />
      <TweakToggle label="Show device sidecar (image, floor plan)" value={t.showSidecar} onChange={v => setTweak("showSidecar", v)} />
      <TweakToggle label="Show floor plan map" value={t.showFloorplan} onChange={v => setTweak("showFloorplan", v)} />
    </TweakSection>
    <TweakSection title="Visual">
      <TweakColor label="Primary accent" value={t.accent} options={["#d92929","#5b8cff","#34d399","#7c5cff","#f5b300"]} onChange={v => setTweak("accent", v)} />
      <TweakSelect label="Mono font" value={t.fontMono} options={[
        { value: "JetBrains Mono", label: "JetBrains Mono" },
        { value: "IBM Plex Mono",  label: "IBM Plex Mono" },
        { value: "ui-monospace",   label: "System mono" }
      ]} onChange={v => setTweak("fontMono", v)} />
      <TweakToggle label="Show data-source badges (ZBX/PF/EXT)" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
    </TweakSection>
    <TweakSection title="Quick actions">
      <TweakButton onClick={() => alert("This would re-poll Zabbix items via API.")}>Force Zabbix re-poll</TweakButton>
      <TweakButton onClick={() => alert("This would request a fresh PacketFence client snapshot.")}>Refresh PacketFence cache</TweakButton>
    </TweakSection>
  </TweaksPanel>
);

// ───────── Debug panel — surface bridge state when no data is loading ─────────
const DebugPanel = () => {
  const [, force] = React.useState(0);
  const [open, setOpen] = React.useState(true);
  React.useEffect(() => {
    const bump = () => force(n => n + 1);
    window.addEventListener("tcs:debug", bump);
    window.addEventListener("tcs:data",  bump);
    return () => {
      window.removeEventListener("tcs:debug", bump);
      window.removeEventListener("tcs:data",  bump);
    };
  }, []);

  const d = window.TCS_DEBUG || {};
  const host  = window.ZBX_HOST   || {};
  const items = window.ZBX_ITEMS  || {};
  const alerts = window.ALERTS_SUMMARY || {};

  const collections = {
    SYSTEM_INFO:  Array.isArray(window.SYSTEM_INFO)  ? window.SYSTEM_INFO.length  : 0,
    NETWORK_INFO: Array.isArray(window.NETWORK_INFO) ? window.NETWORK_INFO.length : 0,
    ZBX_EVENTS:   Array.isArray(window.ZBX_EVENTS)   ? window.ZBX_EVENTS.length   : 0,
    WIRED_PORTS:  Array.isArray(window.WIRED_PORTS)  ? window.WIRED_PORTS.length  : 0,
    PF_CLIENTS:   Array.isArray(window.PF_CLIENTS)   ? window.PF_CLIENTS.length   : 0,
    PF_AUTH_FAILS:Array.isArray(window.PF_AUTH_FAILS)? window.PF_AUTH_FAILS.length: 0,
    AP_SITES:     Array.isArray(window.AP_SITES)     ? window.AP_SITES.length     : 0
  };

  const itemRows = Object.entries(items).map(([k, v]) => ({
    name: k,
    missing: v && v.missing,
    value: v && v.value,
    unit: v && v.unit,
    key: v && v.key,
    histLen: v && Array.isArray(v.history) ? v.history.length : 0
  }));

  const liveOk = d.lastFetchOk === true;
  const liveErr = d.lastFetchOk === false;

  return (
    <div className="card debug-panel" style={{ marginTop: 14, border: "1px dashed var(--line-2)" }}>
      <div className="card-h" style={{ cursor: "pointer" }} onClick={() => setOpen(o => !o)}>
        <h3>Debug · Data Bridge</h3>
        <span style={{
          marginLeft: 8, fontSize: 10, padding: "2px 8px", borderRadius: 999,
          background: liveOk ? "rgba(52,211,153,0.15)" : liveErr ? "rgba(242,95,92,0.18)" : "rgba(245,179,0,0.18)",
          color: liveOk ? "var(--ok)" : liveErr ? "var(--err)" : "var(--warn)",
          border: `1px solid ${liveOk ? "rgba(52,211,153,0.4)" : liveErr ? "rgba(242,95,92,0.4)" : "rgba(245,179,0,0.4)"}`
        }}>
          {liveOk ? "live refresh OK" : liveErr ? "live refresh ERROR" : "no refresh yet"}
        </span>
        <div className="h-spacer" />
        <button
          className="btn sm"
          onClick={(e) => { e.stopPropagation(); if (window.tcsDashboardRefresh) window.tcsDashboardRefresh(); }}
        >Refresh now</button>
        <span className="h-meta" style={{ marginLeft: 10 }}>{open ? "▼" : "▶"}</span>
      </div>
      {!open ? null : (
        <div className="card-b" style={{ display: "grid", gap: 14, fontSize: 11, fontFamily: "var(--mono)" }}>
          <DebugSection title="Bridge state">
            <DebugKV k="boot applied"     v={String(!!d.bootApplied)} />
            <DebugKV k="data URL"         v={d.url || "—"} />
            <DebugKV k="last fetch"       v={d.lastFetchAt || "never"} />
            <DebugKV k="last fetch ok"    v={d.lastFetchOk === null ? "—" : String(d.lastFetchOk)} tone={liveErr ? "err" : liveOk ? "ok" : null} />
            <DebugKV k="fetch count"      v={String(d.fetchCount ?? 0)} />
            <DebugKV k="last error"       v={d.lastError || "—"} tone={d.lastError ? "err" : null} />
          </DebugSection>

          <DebugSection title="ZBX_HOST">
            <DebugKV k="hostid"        v={host.hostid || "(empty — backend returned no host)"} tone={!host.hostid ? "err" : null} />
            <DebugKV k="host"          v={host.host || "—"} />
            <DebugKV k="visible_name"  v={host.visible_name || "—"} />
            <DebugKV k="ip"            v={host.ip || "—"} />
            <DebugKV k="available"     v={host.available === 1 ? "1 (up)" : host.available === 2 ? "2 (down)" : String(host.available ?? "—")} />
            <DebugKV k="uptime (sec)"  v={String(host.uptime ?? "—")} />
            <DebugKV k="templates"     v={(host.templates || []).join(", ") || "—"} />
            <DebugKV k="groups"        v={(host.groups || []).join(", ") || "—"} />
            <DebugKV k="proxy"         v={host.proxy || "(direct)"} />
          </DebugSection>

          <DebugSection title={`ZBX_ITEMS (${itemRows.length} keys)`}>
            <table className="tbl" style={{ width: "100%", fontSize: 11 }}>
              <thead>
                <tr>
                  <th>logical</th><th>missing</th><th>value</th><th>unit</th><th>hist</th><th>matched key</th>
                </tr>
              </thead>
              <tbody>
                {itemRows.map(r => (
                  <tr key={r.name}>
                    <td className="fg">{r.name}</td>
                    <td style={{ color: r.missing ? "var(--err)" : "var(--ok)" }}>{String(!!r.missing)}</td>
                    <td>{r.value === null || r.value === undefined ? "—" : String(r.value)}</td>
                    <td>{r.unit || "—"}</td>
                    <td>{r.histLen}</td>
                    <td style={{ color: "var(--muted)" }}>{r.key || "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </DebugSection>

          <DebugSection title="ALERTS_SUMMARY">
            {Object.entries(alerts).map(([k, v]) => (
              <DebugKV key={k} k={k} v={String(v)} />
            ))}
          </DebugSection>

          <DebugSection title="Clients pipeline (XIQ → PF enrich)">
            {(() => {
              const cd = window.TCS_CLIENTS_DEBUG || {};
              const entries = Object.entries(cd);
              if (entries.length === 0) {
                return <div style={{ color: "var(--muted)" }}>(no diagnostic — collector didn't run; load with ?hostid=N)</div>;
              }
              return entries.map(([k, v]) => (
                <DebugKV key={k} k={k} v={String(v)} tone={(k === "stage" || k === "pfStage") ? "warn" : null} />
              ));
            })()}
          </DebugSection>

          <DebugSection title="Collection sizes">
            {Object.entries(collections).map(([k, v]) => (
              <DebugKV key={k} k={k} v={String(v)} tone={v === 0 ? "warn" : null} />
            ))}
          </DebugSection>

          <DebugSection title="Raw ZBX_BOOT (server-inlined)">
            <details>
              <summary style={{ cursor: "pointer", color: "var(--muted)" }}>
                Click to expand
              </summary>
              <pre style={{
                marginTop: 8, padding: 10, background: "var(--bg-2)",
                border: "1px solid var(--line)", borderRadius: 4,
                fontSize: 10.5, lineHeight: 1.4, maxHeight: 320,
                overflow: "auto", whiteSpace: "pre-wrap", wordBreak: "break-word"
              }}>
                {(() => { try { return JSON.stringify(d.bootRaw, null, 2); } catch { return "(unserializable)"; }})()}
              </pre>
            </details>
          </DebugSection>
        </div>
      )}
    </div>
  );
};

const DebugSection = ({ title, children }) => (
  <div>
    <div style={{
      fontFamily: "var(--sans)", fontSize: 10, textTransform: "uppercase",
      letterSpacing: 0.6, color: "var(--muted)", marginBottom: 6,
      paddingBottom: 4, borderBottom: "1px solid var(--line)"
    }}>{title}</div>
    <div>{children}</div>
  </div>
);

const DebugKV = ({ k, v, tone }) => {
  const color = tone === "err" ? "var(--err)" : tone === "warn" ? "var(--warn)" : tone === "ok" ? "var(--ok)" : "var(--fg-2)";
  return (
    <div style={{ display: "grid", gridTemplateColumns: "180px 1fr", gap: 8, padding: "2px 0" }}>
      <div style={{ color: "var(--muted)" }}>{k}</div>
      <div style={{ color, wordBreak: "break-all" }}>{v}</div>
    </div>
  );
};

window.Sidebar = Sidebar;
window.Topbar = Topbar;
window.PageHeader = PageHeader;
window.Tabs = Tabs;
window.DeviceSidecar = DeviceSidecar;
window.APNavigator = APNavigator;
window.CommandPalette = CommandPalette;
window.Tweaks = Tweaks;
window.DebugPanel = DebugPanel;
window.TWEAK_DEFAULTS = TWEAK_DEFAULTS;
