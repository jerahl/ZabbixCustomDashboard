// Switches dashboard widgets — host navigator, switch port viewer, problems

const { useState: useStateSW } = React;

// ───────── Host Navigator ─────────
// activeId can be either a numeric hostid (live data) or a host shortname
// (mock data fallback). Rows with a `hostid` field navigate to that switch
// by reloading the page with ?switchid=<hostid>; rows without it fall back
// to onSelect() for in-page selection (mock mode).
const HostNavigator = ({ activeId, onSelect }) => {
  const [sites, setSites] = useStateSW(window.SWITCH_SITES);
  const [loading, setLoading] = useStateSW(() => !!(window.SWITCH_LOADING && window.SWITCH_LOADING.fleet));
  // The bridge updates window.SWITCH_SITES in-place after the fleet fetch
  // resolves. Re-sync our local state on each tcs:switch-data event,
  // preserving expand/collapse choices by id so user toggles don't get
  // clobbered when a refresh lands.
  React.useEffect(() => {
    const sync = () => {
      const fresh = window.SWITCH_SITES || [];
      setSites(prev => {
        if (!prev || prev.length === 0) return fresh;
        const expandedById = Object.create(null);
        for (const s of prev) expandedById[s.id] = !!s.expanded;
        return fresh.map(s => ({
          ...s,
          expanded: (s.id in expandedById) ? expandedById[s.id] : s.expanded
        }));
      });
      setLoading(!!(window.SWITCH_LOADING && window.SWITCH_LOADING.fleet));
    };
    window.addEventListener("tcs:switch-data", sync);
    return () => window.removeEventListener("tcs:switch-data", sync);
  }, []);
  const toggle = (idx) => {
    setSites(sites.map((s, i) => i === idx ? { ...s, expanded: !s.expanded } : s));
  };
  const isActive = (sw) => {
    if (!activeId) return !!sw.selected;
    const a = String(activeId);
    return a === String(sw.hostid || "") || a === String(sw.id);
  };
  const onRowClick = (sw) => {
    // Always let the parent update activeId so the page header / KPI tiles
    // re-bind to the new switch immediately. Then fire SPA-style navigation
    // which kicks off the snapshot fetch in the background.
    onSelect(sw.id);
    if (sw.hostid && typeof window.tcsNavigateSwitch === "function") {
      window.tcsNavigateSwitch(sw.hostid);
    }
  };
  const totalHosts = sites.reduce((n, s) => n + (s.switches || []).length, 0);
  return (
    <div className="card">
      <div className="card-h">
        <h3>Host navigator</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">
          {loading && totalHosts === 0
            ? <span className="hn-loading-inline"><span className="hn-spinner" /> loading…</span>
            : `${totalHosts} switches`}
        </span>
      </div>
      <div className="host-nav">
        {loading && sites.length === 0 && (
          <div className="hn-loading">
            <span className="hn-spinner" />
            <span className="hn-loading-lbl">Loading fleet…</span>
          </div>
        )}
        {sites.map((site, i) => (
          <div className="host-nav-section" key={site.id}>
            <div
              className={"host-nav-site" + (site.expanded ? "" : " collapsed")}
              onClick={() => toggle(i)}
            >
              <svg className="caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                <path d="m4 6 4 4 4-4" />
              </svg>
              <span className="site-name">{site.name}</span>
              {site.problems > 0 && <span className="site-prob">{site.problems}</span>}
            </div>
            <div className={"host-nav-children" + (site.expanded ? "" : " hidden")}>
              {site.switches.map(sw => (
                <div
                  key={sw.hostid || sw.id}
                  className={"host-nav-host" + (isActive(sw) ? " active" : "")}
                  onClick={() => onRowClick(sw)}
                  title={sw.ip ? `${sw.id} · ${sw.ip}` : sw.id}
                >
                  <span className="h-id">{sw.id}</span>
                  {sw.problems > 0 && <span className="h-prob">●</span>}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

// ───────── Single port cell ─────────
const Port = ({ p, selected, onClick }) => {
  if (p.state === "absent") {
    return (
      <div className="port absent" title={`Port ${p.n} — not present`}>
        <div className="pn">{p.n}</div>
        <div className="body" />
        <div style={{ height: 4 }} />
      </div>
    );
  }
  const speedClass = p.state === "up" ? `spd-${p.speed}` : "";
  const cls = ["port", p.state, speedClass, p.poe ? "poe" : "", p.err ? "err" : "", p.alert ? "alert" : "", p.state === "down" ? "searching" : "", selected ? "selected" : ""].filter(Boolean).join(" ");
  const speedLbl = p.speed === 10000 ? "10G" : p.speed === 1000 ? "1G" : p.speed === 100 ? "100M" : "10M";
  return (
    <div className={cls} onClick={onClick} title={`Port ${p.n} · ${p.state}${p.state === "up" ? " · " + speedLbl : ""}${p.poe ? " · PoE" : ""}`}>
      <div className="pn">{p.n}</div>
      <div className="body">
        <span className="led led-link" />
        <span className={"led led-speed " + speedClass} />
      </div>
      <div style={{ height: 4 }} />
    </div>
  );
};

// ───────── Member port grid (28 ports per row, two rows) ─────────
const MemberGrid = ({ member, selected, onSelect }) => {
  const odds  = member.ports.filter(p => p.n % 2 === 1);
  const evens = member.ports.filter(p => p.n % 2 === 0);
  // repeat(0, …) is invalid CSS — fall back to 1 so an empty regular grid
  // still produces a renderable (zero-height) track instead of breaking
  // layout flow.
  const cols = Math.max(1, odds.length, evens.length);
  const isSel = (n) => selected && selected.member === member.idx && selected.port === n;
  const hasSfp = Array.isArray(member.sfp) && member.sfp.length > 0;
  return (
    <div className="swport-row" style={hasSfp ? null : { gridTemplateColumns: "1fr" }}>
      <div style={{ display: "grid", gridTemplateRows: "1fr 1fr", gap: 5, minWidth: 0 }}>
        <div className="swport-grid" style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`, minWidth: 0 }}>
          {odds.map(p => <Port key={p.n} p={p} selected={isSel(p.n)} onClick={() => onSelect(member.idx, p)} />)}
        </div>
        <div className="swport-grid" style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`, minWidth: 0 }}>
          {evens.map(p => <Port key={p.n} p={p} selected={isSel(p.n)} onClick={() => onSelect(member.idx, p)} />)}
        </div>
      </div>
      {hasSfp && (
        <div className="swport-sfp">
          <div className="sfp-label">SFP</div>
          {member.sfp.map(s => (
            <div key={s.n} className={"sfp-port " + s.state} title={`SFP ${s.n} · ${s.state}`} onClick={() => onSelect(member.idx, { ...s, state: s.state, n: s.n, speed: s.speed, poe: false })}>
              <div className="core" />
              <div className="pn">{s.n}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

// ───────── Port detail panes ─────────
const PacketFenceDevicePane = ({ host, detail }) => {
  if (!detail || !detail.device) {
    return (
      <div className="pf-pane">
        <div className="pf-head">
          <span className="pf-host">PacketFence device</span>
          <SourceBadge src="pf" />
        </div>
        <div className="pf-card">
          <div className="pf-empty">{detail ? "No registered device on this port" : "Click a port to see device"}</div>
        </div>
      </div>
    );
  }
  const d = detail.device;
  return (
    <div className="pf-pane">
      <div className="pf-head">
        <span className="pf-host">PacketFence device</span>
        <SourceBadge src="pf" />
      </div>
      <div className="pf-head" style={{ marginBottom: 10, fontSize: 11 }}>
        <span style={{ fontFamily: "var(--mono)", color: "var(--fg)" }}>{host.id}</span>
        <span className="pf-ifx">ifIndex {detail.ifIndex}</span>
        <span className="pf-ifx">{1 + (detail.extraMacs || 0)} MAC{detail.extraMacs ? "S" : ""}</span>
        <span className="pf-age">{detail.ageMin < 60 ? `${detail.ageMin}m old` : `${Math.round(detail.ageMin/60)}h old`}</span>
      </div>
      <div className="pf-card">
        <div className="pf-mac">
          <span>{d.mac}</span>
          <span className={"reg-badge " + (d.reg === "REG" ? "reg" : "unreg")}>{d.reg}</span>
        </div>
        <div className="pf-kv">
          <div>
            <div className="k">IP</div>
            <div className="v">{d.ip} <SourceBadge src="pf" /></div>
          </div>
          <div>
            <div className="k">Hostname</div>
            <div className="v">{d.host}</div>
          </div>
          <div>
            <div className="k">Vendor</div>
            <div className="v sans">{d.vendor}</div>
          </div>
          <div>
            <div className="k">OS</div>
            <div className="v sans">{d.os}</div>
          </div>
          <div>
            <div className="k">Owner</div>
            <div className="v" style={{fontSize: 10.5}}>{d.owner}</div>
          </div>
          <div>
            <div className="k">DHCP FP</div>
            <div className="v" style={{fontSize: 10.5}}>{d.dhcpFp.length > 22 ? d.dhcpFp.slice(0, 22) + "…" : d.dhcpFp}</div>
          </div>
          <div>
            <div className="k">Last Seen</div>
            <div className="v">{d.lastSeen}</div>
          </div>
          <div>
            <div className="k">Last ARP</div>
            <div className="v muted" style={{color: "var(--muted)"}}>0000-00-00 00:00:00</div>
          </div>
          <div>
            <div className="k">Last DHCP</div>
            <div className="v">{d.lastDhcp}</div>
          </div>
          <div>
            <div className="k">Role</div>
            <div className="v sans"><span className={`role-tag ${d.role}`}>{d.role}</span></div>
          </div>
          <div>
            <div className="k">Switch</div>
            <div className="v">{host.id}</div>
          </div>
          <div>
            <div className="k">Port</div>
            <div className="v">{detail.label}</div>
          </div>
        </div>
      </div>
    </div>
  );
};

const formatRate = (kbps) => {
  if (kbps >= 1000) return [(kbps / 1000).toFixed(1), "Mbps"];
  return [kbps.toFixed(1), "Kbps"];
};

// Log-scaled bar width for unbounded counters (errors / discards). 0 → 0%,
// 1 error → small but visible, 100 → ~half, 10k+ → full bar.
const countBarPct = (n) => {
  if (!n || n <= 0) return 0;
  return Math.min(100, Math.max(6, Math.log10(n + 1) * 25));
};

const PortDetailPane = ({ detail, onClose }) => {
  const [cycleState, setCycleState] = React.useState({ busy: false, msg: "" });
  const onCycle = React.useCallback(async () => {
    if (cycleState.busy || !detail || typeof window.tcsCyclePoe !== "function") return;
    // detail.label is "<member>:<port>" — parse to get the args.
    const [m, p] = String(detail.label || "").split(":").map(s => parseInt(s, 10));
    if (!m || !p) {
      setCycleState({ busy: false, msg: "bad port" });
      return;
    }
    setCycleState({ busy: true, msg: "queuing…" });
    const r = await window.tcsCyclePoe(m, p);
    setCycleState({
      busy: false,
      msg: r && r.ok ? (r.message || "queued") : (r && (r.error || r.message)) || "failed"
    });
    setTimeout(() => setCycleState({ busy: false, msg: "" }), 4000);
  }, [detail, cycleState.busy]);
  if (!detail) {
    return (
      <div className="pd-pane">
        <div className="pd-head">
          <span className="pd-title">Switch Port Detail</span>
        </div>
        <div className="pf-empty" style={{padding: "40px 16px"}}>Click a port in the grid below to see details.</div>
      </div>
    );
  }
  const [inV, inU] = formatRate(detail.inKbps);
  const [outV, outU] = formatRate(detail.outKbps);
  const stateLbl = detail.state.toUpperCase();
  return (
    <div className="pd-pane">
      <div className="pd-head">
        <span className="pd-title">Switch Port Detail</span>
        <span style={{fontSize: 13, fontWeight: 600, color: "var(--fg)"}}>Port {detail.label}</span>
        <span className="pd-sep">—</span>
        <span className={"pd-state-badge " + detail.state}>{stateLbl}</span>
      </div>
      <div className="pd-grid">
        {/* Left column: traffic */}
        <div>
          <div className="pd-row">
            <div className="pd-lbl">In <Icon name="events" size={11} /></div>
            <div className="pd-mid"><Sparkline data={detail.inHist} color="var(--info)" width={200} height={28} /></div>
            <div className="pd-val">{inV} <span style={{color:"var(--muted)",fontSize:11}}>{inU}</span></div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">Out <Icon name="events" size={11} /></div>
            <div className="pd-mid"><Sparkline data={detail.outHist} color="var(--pf)" width={200} height={28} /></div>
            <div className="pd-val">{outV} <span style={{color:"var(--muted)",fontSize:11}}>{outU}</span></div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">Utilization <Icon name="events" size={11} /></div>
            <div className="pd-mid"><div className="pd-util"><i className={detail.utilPct > 80 ? "err" : detail.utilPct > 50 ? "warn" : ""} style={{width: `${Math.max(1, detail.utilPct)}%`}}/></div></div>
            <div className="pd-val">{detail.utilPct}%</div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">PoE</div>
            <div className="pd-mid">
              {detail.poe ? (
                <div className="pd-poe-btns">
                  <span className="pd-btn delivering">Delivering Power</span>
                  <button
                    type="button"
                    className="pd-btn cycle"
                    onClick={onCycle}
                    disabled={cycleState.busy}
                    title="Cycle PoE on this port via rConfig"
                    style={{ cursor: cycleState.busy ? "wait" : "pointer", border: 0, font: "inherit" }}
                  >
                    <Icon name="refresh" size={11}/> {cycleState.busy ? "CYCLING…" : "CYCLE"}
                  </button>
                  {cycleState.msg && (
                    <span style={{ fontSize: 10.5, color: "var(--muted)", marginLeft: 6 }}>
                      {cycleState.msg}
                    </span>
                  )}
                </div>
              ) : (
                <span style={{fontSize: 11, color: "var(--muted)"}}>—</span>
              )}
            </div>
            <div className="pd-val muted">{detail.poe ? `${detail.poeWatts} W` : ""}</div>
          </div>
        </div>
        {/* Right column: state / errors / link */}
        <div>
          <div className="pd-row" style={{gridTemplateColumns: "1fr", display: "block", paddingBottom: 10}}>
            <div className="pd-lbl" style={{justifyContent:"space-between", display:"flex"}}>
              <span style={{display:"inline-flex",alignItems:"center",gap:5}}>1H Online State <Icon name="events" size={11}/></span>
              <span style={{fontFamily:"var(--mono)",fontSize:9.5,color:"var(--muted)",textTransform:"none",letterSpacing:0}}>now ›</span>
            </div>
            <div className="pd-heatmap" style={{marginTop: 6}}>
              {detail.onlineHist.map((s, i) => <i key={i} className={s} />)}
            </div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">Errors 1H <Icon name="events" size={11} /></div>
            <div className="pd-mid">
              <div className="pd-util">
                <i className={detail.errors1h > 0 ? "err" : ""} style={{width: `${countBarPct(detail.errors1h)}%`}}/>
              </div>
            </div>
            <div className={"pd-val " + (detail.errors1h > 0 ? "warn" : "muted")} style={{fontSize: 11}}>
              {detail.errors1h} <span style={{color:"var(--muted)"}}>(in {detail.errIn || 0} / out {detail.errOut || 0})</span>
            </div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">Discards 1H <Icon name="events" size={11} /></div>
            <div className="pd-mid">
              <div className="pd-util">
                <i className={detail.discards1h > 0 ? "warn" : ""} style={{width: `${countBarPct(detail.discards1h)}%`}}/>
              </div>
            </div>
            <div className={"pd-val " + (detail.discards1h > 0 ? "warn" : "muted")} style={{fontSize: 11}}>
              {detail.discards1h} <span style={{color:"var(--muted)"}}>(in {detail.discIn || 0} / out {detail.discOut || 0})</span>
            </div>
          </div>
          <div className="pd-row">
            <div className="pd-lbl">Link Speed</div>
            <div className="pd-mid" />
            <div className="pd-val">{detail.speed >= 1000 ? `${detail.speed/1000} Gbps` : `${detail.speed} Mbps`}</div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ───────── Switch Port Status widget ─────────
const SwitchPortWidget = ({ host, selected, onSelectPort }) => {
  const stack = window.ARC_MDF_STACK;
  const totalUp = stack.reduce((n, m) => n + m.upCount, 0);
  const totalDown = stack.reduce((n, m) => n + m.downCount, 0);
  const totalPoe = stack.reduce((n, m) => n + m.poeCount, 0);

  return (
    <div className="card">
      <div className="card-h">
        <h3>Switch Port Status</h3>
        <SourceBadge src="ext" />
        <div className="h-spacer" />
        <span className="h-meta">ExtremeCloud IQ · 8s refresh</span>
        <span className="h-link">Open in ExtremeCloud <Icon name="external" size={11} /></span>
      </div>

      <div className="swport-head">
        <div className="swport-title">
          <span className="id">{host.id}</span>
          <div className="swport-legend">
            <span className="item"><span className="swatch" style={{ background: "var(--ok)" }}></span> Up ({totalUp})</span>
            <span className="item"><span className="swatch" style={{ background: "#1a1e28", borderColor: "var(--line)" }}></span> Down ({totalDown})</span>
            <span className="item"><span className="swatch" style={{ background: "var(--bg-2)", border: "1px solid var(--line)" }}></span> Disabled (0)</span>
            <span className="item"><span className="swatch" style={{ background: "transparent", border: "1px dashed var(--line)" }}></span> Not Present (32)</span>
            <span className="item"><span className="dot-led" style={{ background: "var(--warn)", boxShadow: "0 0 4px var(--warn)" }}></span> PoE On ({totalPoe})</span>
            <span className="item"><span className="dot-led" style={{ background: "var(--info)" }}></span> Searching (137)</span>
          </div>
          <div className="swport-legend" style={{ marginLeft: "auto" }}>
            <span className="item"><span className="swatch" style={{ background: "#f0a52c" }}></span> 10 Mbps (2)</span>
            <span className="item"><span className="swatch" style={{ background: "#c9d62b" }}></span> 100 Mbps ({Math.round(totalUp * 0.18)})</span>
            <span className="item"><span className="swatch" style={{ background: "var(--ok)" }}></span> 1 Gbps ({Math.round(totalUp * 0.78)})</span>
            <span className="item"><span className="swatch" style={{ background: "#2bd6c0" }}></span> 10 Gbps ({Math.round(totalUp * 0.04)})</span>
          </div>
        </div>
      </div>

      <div className="swport-toolbar">
        <span className="chip-btn ok"><span className="dot" style={{ background: "var(--ok)" }} /><span className="lbl-mono">CPU</span> {host.cpu}%</span>
        <span className="chip-btn ok"><span className="dot" style={{ background: "var(--ok)" }} /><span className="lbl-mono">MEM</span> {host.mem}%</span>
        <span className="chip-btn warn"><span className="dot" style={{ background: "var(--warn)" }} /><span className="lbl-mono">{host.temp}°C</span></span>
        <span className="chip-btn ok"><span className="dot" style={{ background: "var(--ok)" }} /><span className="lbl-mono">PSU</span></span>
        <span className="chip-btn ok"><span className="dot" style={{ background: "var(--ok)" }} /><span className="lbl-mono">FAN</span></span>
        <div className="ip-badge">IP ADDRESS <span className="v">{host.ip}</span></div>
      </div>

      {stack.map(m => (
        <div className="swport-member" key={m.idx}>
          <div className="swport-member-head">
            <span className="m-id">MEMBER <span className="m-num">{m.idx}</span></span>
            <span className="m-stats"><span className="up">{m.upCount} up</span> / <span className="down">{m.downCount} down</span></span>
            <span className="m-stats poe">
              <svg width="10" height="12" viewBox="0 0 10 12" fill="currentColor"><path d="M6 0 0 7h4l-1 5 6-7H5l1-5Z"/></svg>
              {m.poeCount} PoE on
            </span>
          </div>
          <MemberGrid member={m} selected={selected ? { member: selected.member, port: selected.port } : null} onSelect={onSelectPort} />
        </div>
      ))}
    </div>
  );
};

// ───────── Problems widget ─────────
const ProblemsWidget = () => {
  const items = window.SWITCH_PROBLEMS;
  return (
    <div className="card">
      <div className="card-h">
        <h3>Problems</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <Icon name="filter" size={12} />
        <Icon name="more" size={14} />
      </div>
      <div style={{ padding: "8px 14px 6px", fontSize: 11, color: "var(--muted)", letterSpacing: 0.4, textTransform: "uppercase", borderBottom: "1px solid var(--line)" }}>
        Triggers · last 24h
      </div>
      {items.length === 0 ? (
        <div className="empty-state">
          <div className="ico"><Icon name="search" size={20} /></div>
          <div className="lbl">No data found</div>
        </div>
      ) : (
        <div>
          {items.map((p, i) => (
            <div key={i} className={"problem-row " + (p.ack ? "ack" : "")}>
              <div className="top">
                <Sev level={p.sev} />
                <span className="host">{p.host}</span>
                <span className="age">{p.age}</span>
              </div>
              <div className="trig">{p.trig}</div>
              <div className="ts">{p.ts}{p.ack && " · ack"}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

// ───────── Stack KPI strip ─────────
const StackKPIs = ({ host }) => {
  const stack = window.ARC_MDF_STACK;
  const totalUp = stack.reduce((n, m) => n + m.upCount, 0);
  const H = window.ARC_MDF_HISTORY;
  const K = window.SWITCH_KPIS || {};

  const fmt = (v, suffix = "") =>
    (v === null || v === undefined) ? "—" : (Math.round(v * 10) / 10) + suffix;

  const cpuVal  = K.cpu  !== null && K.cpu  !== undefined ? Math.round(K.cpu)  : host.cpu;
  const tempVal = K.temp !== null && K.temp !== undefined ? Math.round(K.temp) : host.temp;
  const poeW    = K.poeWatts;
  const poeMax  = K.poeBudget;

  // Peak uplink RX from the history series, converted to a friendly unit.
  const peakRx  = H.uplinkRx && H.uplinkRx.length ? Math.max(...H.uplinkRx) : 0;
  const peakRxV = peakRx >= 1000 ? (peakRx / 1000).toFixed(1) : Math.round(peakRx);
  const peakRxU = peakRx >= 1000 ? "Gbps" : "Mbps";

  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="swstat-strip">
        <div className="swstat-cell">
          <div className="lbl">Stack Members</div>
          <div className="val">{stack.length}<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> / 8 max</span></div>
          <div style={{fontSize:10,color:"var(--ok)",fontFamily:"var(--mono)"}}>● all up</div>
        </div>
        <div className="swstat-cell">
          <div className="lbl">Active Ports</div>
          <div className="val">{totalUp}<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> / {host.ports}</span></div>
          <Sparkline data={(H.uplinkRx || []).map(v => Math.round(v / 30 + 60))} color="var(--ok)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">PoE Budget</div>
          <div className="val">
            {fmt(poeW)}
            <span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> W{poeMax ? ` / ${Math.round(poeMax)}` : ""}</span>
          </div>
          <Sparkline data={H.poeWatts || []} color="var(--warn)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">Uplink RX (peak)</div>
          <div className="val">{peakRxV}<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> {peakRxU}</span></div>
          <Sparkline data={H.uplinkRx || []} color="var(--zbx)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">CPU · 1m</div>
          <div className="val ok">{cpuVal}%</div>
          <Sparkline data={H.cpu || []} color="var(--info)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">Temp (max)</div>
          <div className="val warn">{tempVal}°C</div>
          <Sparkline data={H.temp || []} color="var(--pf)" width={120} height={20} threshold={75} />
        </div>
      </div>
    </div>
  );
};

// ───────── Uplink table ─────────
const UplinkTable = () => (
  <div className="card">
    <div className="card-h">
      <h3>Uplinks · Top Talkers</h3>
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <span className="h-meta">SNMP IF-MIB · 30s poll</span>
    </div>
    <table className="link-tbl">
      <thead>
        <tr>
          <th style={{width: 60}}>Port</th>
          <th style={{width: 70}}>Type</th>
          <th>Peer</th>
          <th style={{width: 90, textAlign: "right"}}>RX</th>
          <th style={{width: 90, textAlign: "right"}}>TX</th>
          <th style={{width: 130}}>Util</th>
          <th style={{width: 50, textAlign: "right"}}>Err</th>
        </tr>
      </thead>
      <tbody>
        {window.ARC_MDF_LINKS.map(l => (
          <tr key={l.name}>
            <td className="fg" style={{color:"var(--accent)"}}>{l.name}</td>
            <td>{l.type}</td>
            <td>{l.peer}</td>
            <td style={{textAlign:"right"}}>{l.rxMbps} Mbps</td>
            <td style={{textAlign:"right"}}>{l.txMbps} Mbps</td>
            <td>
              <span className="util-bar"><i className={l.util > 50 ? "warn" : ""} style={{width: `${Math.max(2, l.util)}%`}}/></span>
              {l.util}%
            </td>
            <td style={{textAlign:"right", color: l.errors > 0 ? "var(--warn)" : "var(--muted)"}}>{l.errors}</td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

// ───────── Combined port detail row (used between stack and uplinks) ─────────
const PortDetailRow = ({ host, detail }) => (
  <div className="card">
    <div className="card-h">
      <h3>Port Detail</h3>
      <SourceBadge src="pf" />
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <span className="h-meta">{detail ? `${host.id} · Port ${detail.label}` : "click any port above"}</span>
    </div>
    <div className="port-detail-row">
      <PacketFenceDevicePane host={host} detail={detail} />
      <PortDetailPane detail={detail} />
    </div>
  </div>
);
window.PortDetailRow = PortDetailRow;
window.SwitchPortWidget = SwitchPortWidget;
window.PacketFenceDevicePane = PacketFenceDevicePane;
window.PortDetailPane = PortDetailPane;
window.ProblemsWidget = ProblemsWidget;
window.StackKPIs = StackKPIs;
window.UplinkTable = UplinkTable;
