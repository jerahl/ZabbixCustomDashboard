// Surveillance NOC — Sites / Cameras / Recording Servers / Alarms /
// Storage / Evidence Lock tab views. Ported from the design package,
// adapted to use the live bridge state (no mock fixtures).
//
// Defensive reads everywhere — the bridge always publishes the
// MILESTONE / SITES / SERVERS / CAMERAS / VMS_ALARMS globals, but
// SITE_DETAILS and EVIDENCE_LOCKS aren't yet templated on the
// backend; both default to an empty object / array so the tabs
// render an honest empty state instead of crashing.

const { useState: useStateNVT, useMemo: useMemoNVT } = React;

const _tabsNz = (v, d = 0) => (typeof v === "number" && !Number.isNaN(v) ? v : d);
const _tabsArr = (v) => (Array.isArray(v) ? v : []);
const _tabsObj = (v) => (v && typeof v === "object" && !Array.isArray(v) ? v : {});

// Empty-state renderer reused across tabs.
const _TabEmpty = ({ children }) => (
  <div className="card" style={{ padding: 32, textAlign: "center", color: "var(--muted)" }}>{children}</div>
);

// ─────────────────────────────────────────────────────────────
// SITES
// ─────────────────────────────────────────────────────────────
const NvrTabSites = () => {
  const S = _tabsArr(window.SITES);
  const D = _tabsObj(window.SITE_DETAILS);
  const total  = S.reduce((a, x) => a + _tabsNz(x.cams), 0);
  const online = S.reduce((a, x) => a + _tabsNz(x.online), 0);
  if (S.length === 0) {
    return <div className="tab-pane"><_TabEmpty>No Milestone sites discovered yet.</_TabEmpty></div>;
  }
  const edges = Object.values(D).reduce((a, d) => a + _tabsNz(d.edges), 0);
  return (
    <div className="tab-pane">
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="stat-grid">
          <div className="stat-cell">
            <div className="lbl"><Icon name="ap" size={11}/> Sites <SourceBadge src="ext" /></div>
            <div className="val">{S.length}<span className="u">campuses</span></div>
            <div className="sub">{edges ? `${edges} edge buildings` : "—"}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="ap" size={11}/> Cameras (site total)</div>
            <div className="val">{online.toLocaleString()}<span className="u">/ {total.toLocaleString()}</span></div>
            <div className="sub ok">{total > 0 ? `${(online/total*100).toFixed(1)}% online` : "—"}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="ethernet" size={11}/> Sites w/ issues</div>
            <div className="val" style={{ color: "var(--warn)" }}>{S.filter(s => s.warn || s.err).length}</div>
            <div className="sub warn">{S.filter(s => s.err).length} with offline cameras</div>
          </div>
          <div className="stat-cell">
            <div className="lbl"><Icon name="alert" size={11}/> Storage near limit</div>
            <div className="val" style={{ color: "var(--warn)" }}>{S.filter(s => _tabsNz(s.storageCapGB,1) && (s.storageGB/s.storageCapGB) > 0.9).length}<span className="u">/ {S.length}</span></div>
            <div className="sub">retention may roll early</div>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Sites &amp; campus rollup</h3>
          <SourceBadge src="ext"/>
          <SourceBadge src="zbx"/>
          <div className="h-spacer"/>
          <span className="h-meta">click any site to drill into XProtect site view</span>
        </div>
        <table className="link-tbl nvr-tbl">
          <thead>
            <tr>
              <th style={{ width: 24 }}></th>
              <th>Site</th>
              <th style={{ width: 140 }}>Recording server</th>
              <th style={{ width: 90, textAlign: "right" }}>Cameras</th>
              <th style={{ width: 100 }}>Health</th>
              <th style={{ width: 220 }}>Storage</th>
              <th style={{ width: 110 }}>Network</th>
              <th style={{ width: 60, textAlign: "right" }}>APs</th>
              <th style={{ width: 32 }}></th>
            </tr>
          </thead>
          <tbody>
            {S.map(s => {
              const d = D[s.name] || {};
              const cap = _tabsNz(s.storageCapGB, 1);
              const pct = cap > 0 ? (_tabsNz(s.storageGB) / cap) * 100 : 0;
              const state = s.err ? "err" : s.warn ? "warn" : "ok";
              return (
                <tr key={s.name}>
                  <td><StatusDot state={state}/></td>
                  <td>
                    <div style={{ color: "var(--fg)", fontWeight: 500 }}>{s.name}</div>
                    <div style={{ color: "var(--muted)", fontSize: 10.5 }}>
                      {[d.address, d.edges ? `${d.edges} buildings` : null, d.switches ? `${d.switches} switches` : null].filter(Boolean).join(" · ") || "—"}
                    </div>
                  </td>
                  <td className="mono" style={{ color: "var(--accent)" }}>{s.server || "—"}</td>
                  <td className="mono" style={{ textAlign: "right" }}>
                    <span className="ok">{_tabsNz(s.online)}</span>
                    <span style={{ color: "var(--muted)" }}> / {_tabsNz(s.cams)}</span>
                  </td>
                  <td>
                    {s.warn === 0 && s.err === 0
                      ? <span className="state-pill ok">all clear</span>
                      : <span className="state-pill warn">
                          {s.warn > 0 && <span>{s.warn}w</span>}
                          {s.err  > 0 && <span style={{ color: "var(--err)" }}> · {s.err}e</span>}
                        </span>}
                  </td>
                  <td>
                    {cap > 1 ? (
                      <div className="storage-bar compact">
                        <div className="label-row">
                          <span className="name muted" style={{ fontFamily: "var(--mono)" }}>{(_tabsNz(s.storageGB)/1000).toFixed(1)} / {(cap/1000).toFixed(0)} TB</span>
                          <span className="pct">{pct.toFixed(0)}%</span>
                        </div>
                        <div className="track"><div className={"fill " + (pct > 90 ? "err" : pct > 80 ? "warn" : "")} style={{ width: `${pct}%` }}/></div>
                      </div>
                    ) : <span style={{ color: "var(--muted)", fontSize: 10.5 }}>—</span>}
                  </td>
                  <td className="mono" style={{ fontSize: 11 }}>
                    <div>{d.network || "—"}</div>
                    {d.vlan ? <div style={{ color: "var(--muted)", fontSize: 10 }}>VLAN {d.vlan}</div> : null}
                  </td>
                  <td className="mono" style={{ textAlign: "right", color: "var(--muted)" }}>{d.aps || "—"}</td>
                  <td style={{ color: "var(--muted)", textAlign: "right" }}><Icon name="chevron" size={12}/></td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────
// CAMERAS
// ─────────────────────────────────────────────────────────────
const NvrTabCameras = () => {
  const all = _tabsArr(window.CAMERAS);
  const SITES = ["All", ...new Set(all.map(c => c.site))];
  const STATES = [
    { id: "all",  label: "All",     count: all.length },
    { id: "ok",   label: "Online",  count: all.filter(c => c.state === "ok").length },
    { id: "warn", label: "Warning", count: all.filter(c => c.state === "warn").length },
    { id: "err",  label: "Offline", count: all.filter(c => c.state === "err").length }
  ];
  const [site, setSite]   = useStateNVT("All");
  const [state, setState] = useStateNVT("all");
  const [q, setQ]         = useStateNVT("");

  const rows = all.filter(c =>
    (site === "All" || c.site === site)
    && (state === "all" || c.state === state)
    && (!q || ((c.id || "") + (c.loc || "") + (c.model || "") + (c.ip || "")).toLowerCase().includes(q.toLowerCase()))
  );

  const M = Object.assign({ licenseDeviceUsed: 0 }, window.MILESTONE || {});

  return (
    <div className="tab-pane">
      <div className="card-h-bar">
        <span className="h-title">Camera fleet — {_tabsNz(M.licenseDeviceUsed).toLocaleString()} licensed</span>
        <SourceBadge src="ext" />
        <div className="h-spacer" />
        <span className="h-meta">showing {rows.length.toLocaleString()} of {all.length.toLocaleString()}</span>
      </div>

      <div className="cam-filter-bar card" style={{ marginBottom: 12 }}>
        <div className="cfb-group">
          <span className="cfb-lbl">State</span>
          {STATES.map(s => (
            <span key={s.id}
                  className={"cfb-chip " + (state === s.id ? "active" : "") + " " + s.id}
                  onClick={() => setState(s.id)}>
              {s.label} <b>{s.count}</b>
            </span>
          ))}
        </div>
        <div className="cfb-group">
          <span className="cfb-lbl">Site</span>
          <select className="cfb-select" value={site} onChange={e => setSite(e.target.value)}>
            {SITES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div className="cfb-group" style={{ flex: 1 }}>
          <span className="cfb-lbl">Search</span>
          <div className="cfb-search">
            <Icon name="search" size={12}/>
            <input placeholder="cam id, location, IP, model…" value={q} onChange={e => setQ(e.target.value)} />
          </div>
        </div>
      </div>

      <div className="card">
        <table className="link-tbl nvr-tbl cam-tbl">
          <thead>
            <tr>
              <th style={{ width: 20 }}></th>
              <th style={{ width: 110 }}>Camera</th>
              <th>Location</th>
              <th style={{ width: 110 }}>Site</th>
              <th style={{ width: 160 }}>Model</th>
              <th style={{ width: 110 }}>Resolution</th>
              <th style={{ width: 60, textAlign: "right" }}>FPS</th>
              <th style={{ width: 90, textAlign: "right" }}>Bitrate</th>
              <th style={{ width: 110 }}>Recording</th>
              <th style={{ width: 60, textAlign: "right" }}>PoE</th>
              <th style={{ width: 110 }}>Server</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(c => (
              <tr key={c.id}
                  className={c.state === "err" ? "row-err" : c.state === "warn" ? "row-warn" : ""}
                  onClick={() => { if (c.hostid) location.href = `zabbix.php?action=tcs.camera.view&hostid=${c.hostid}`; }}>
                <td><StatusDot state={c.state}/></td>
                <td className="mono" style={{ color: "var(--accent)" }}>{c.id}</td>
                <td>
                  <div style={{ color: "var(--fg)" }}>{c.loc || "—"}</div>
                  {c.warnMsg && <div style={{ color: "var(--warn)", fontSize: 10 }}>{c.warnMsg}</div>}
                  {c.errMsg  && <div style={{ color: "var(--err)",  fontSize: 10 }}>{c.errMsg}</div>}
                </td>
                <td style={{ color: "var(--fg-2)" }}>{c.site}</td>
                <td className="mono" style={{ fontSize: 11 }}>{c.model}</td>
                <td className="mono" style={{ fontSize: 11 }}>{c.res}{c.codec && c.codec !== "—" ? <span style={{ color: "var(--muted)" }}> · {c.codec}</span> : null}</td>
                <td className="mono" style={{ textAlign: "right", color: c.fps === 0 ? "var(--muted)" : c.fps < 20 ? "var(--warn)" : "var(--fg-2)" }}>{c.fps || "—"}</td>
                <td className="mono" style={{ textAlign: "right" }}>{c.bitrate ? `${(c.bitrate/1000).toFixed(1)} Mbps` : "—"}</td>
                <td><span className={"rec-pill " + (!c.recording || c.recording === "—" ? "off" : c.recording === "Motion" ? "alt" : "")}>{c.recording || "—"}</span></td>
                <td className="mono" style={{ textAlign: "right" }}>{c.poe ? `${c.poe.toFixed(1)} W` : "—"}</td>
                <td className="mono" style={{ color: "var(--muted)", fontSize: 11 }}>{(c.server || "").replace("tcs-rec-", "")}</td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr><td colSpan={11} style={{ padding: 22, textAlign: "center", color: "var(--muted)" }}>No cameras match the current filter.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────
// RECORDING SERVERS
// ─────────────────────────────────────────────────────────────
const InlineBar = ({ v, max, warn, crit, unit }) => {
  const val = _tabsNz(v);
  const pct = max > 0 ? (val / max) * 100 : 0;
  const cls = val >= crit ? "err" : val >= warn ? "warn" : "ok";
  return (
    <div className="ib">
      <div className="ib-track"><div className={"ib-fill " + cls} style={{ width: `${pct}%` }}/></div>
      <span className={"ib-val " + cls}>{val}{unit}</span>
    </div>
  );
};

const NvrTabServers = () => {
  const SR = _tabsArr(window.SERVERS);
  const M = Object.assign({
    recordingServers: 0, recordingServersOnline: 0, failoverServers: 0,
    managementServer: "—", version: "—",
    smartClientSessions: 0, webClientSessions: 0
  }, window.MILESTONE || {});
  const totalChans = SR.reduce((a, s) => a + _tabsNz(s.chans), 0);
  const recChans   = SR.reduce((a, s) => a + _tabsNz(s.recording), 0);
  return (
    <div className="tab-pane">
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="stat-grid">
          <div className="stat-cell">
            <div className="lbl">Recording servers <SourceBadge src="zbx"/></div>
            <div className="val">{M.recordingServersOnline}<span className="u">/ {M.recordingServers}</span></div>
            <div className="sub ok">{M.recordingServers > 0 && M.recordingServersOnline === M.recordingServers ? "all online" : ""}{M.failoverServers ? ` · ${M.failoverServers} failover standby` : ""}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Channels recording</div>
            <div className="val">{recChans.toLocaleString()}<span className="u">/ {totalChans.toLocaleString()}</span></div>
            <div className="sub warn">{totalChans - recChans > 0 ? `${totalChans - recChans} channels not recording` : ""}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Mgmt server</div>
            <div className="val" style={{ fontSize: 14 }}>{M.managementServer}</div>
            <div className="sub ok">v {M.version}</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Mobile / web sessions</div>
            <div className="val">{_tabsNz(M.smartClientSessions) + _tabsNz(M.webClientSessions)}</div>
            <div className="sub">{_tabsNz(M.smartClientSessions)} smart · {_tabsNz(M.webClientSessions)} web</div>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Recording servers</h3>
          <SourceBadge src="zbx"/>
          <SourceBadge src="ext"/>
          <div className="h-spacer"/>
          <span className="h-meta">zabbix-agent2 + Dell iDRAC SNMP · 60s poll</span>
        </div>
        {SR.length === 0
          ? <div style={{ padding: 32, textAlign: "center", color: "var(--muted)" }}>No recording servers discovered.</div>
          : <table className="link-tbl nvr-tbl srv-tbl">
              <thead>
                <tr>
                  <th style={{ width: 20 }}></th>
                  <th style={{ width: 170 }}>Host</th>
                  <th style={{ width: 130 }}>Role</th>
                  <th>Site</th>
                  <th style={{ width: 120 }}>IP</th>
                  <th style={{ width: 100 }}>OS</th>
                  <th style={{ width: 100, textAlign: "right" }}>Channels</th>
                  <th style={{ width: 130 }}>CPU</th>
                  <th style={{ width: 130 }}>Mem</th>
                  <th style={{ width: 130 }}>Disk</th>
                  <th style={{ width: 80, textAlign: "right" }}>Lag</th>
                  <th style={{ width: 80 }}>RAID</th>
                  <th style={{ width: 60, textAlign: "right" }}>Up</th>
                </tr>
              </thead>
              <tbody>
                {SR.map(s => {
                  const tileState = s.state || (_tabsNz(s.disk) > 90 || _tabsNz(s.cpu) > 80 || s.raid === "warn" || s.raid === "err" ? "warn" : "ok");
                  return (
                    <tr key={s.id}
                        onClick={() => { if (s.agentHostid) location.href = `zabbix.php?action=tcs.server.view&hostid=${s.agentHostid}`; }}>
                      <td><StatusDot state={tileState}/></td>
                      <td className="mono" style={{ color: "var(--accent)" }}>{s.id}</td>
                      <td>
                        <span className={"role-tag " + (s.role === "Failover" ? "ovr" : s.role === "Management Server" ? "tpl" : "")}>{s.role}</span>
                      </td>
                      <td style={{ color: "var(--fg-2)" }}>{s.site}</td>
                      <td className="mono" style={{ fontSize: 11 }}>{s.ip || "—"}</td>
                      <td style={{ fontSize: 11, color: "var(--muted)" }}>{(s.os || "").replace("Win Server ", "WS ").replace("Microsoft Windows Server ", "WS ") || "—"}</td>
                      <td className="mono" style={{ textAlign: "right" }}>
                        {_tabsNz(s.chans) === 0 ? <span style={{ color: "var(--muted)" }}>—</span> :
                          <>
                            <span style={{ color: _tabsNz(s.recording) < _tabsNz(s.chans) ? "var(--warn)" : "var(--ok)" }}>{s.recording}</span>
                            <span style={{ color: "var(--muted)" }}> / {s.chans}</span>
                          </>
                        }
                      </td>
                      <td><InlineBar v={s.cpu}  max={100} warn={75} crit={90} unit="%" /></td>
                      <td><InlineBar v={s.mem}  max={100} warn={80} crit={92} unit="%" /></td>
                      <td><InlineBar v={s.disk} max={100} warn={80} crit={90} unit="%" /></td>
                      <td className="mono" style={{ textAlign: "right", color: _tabsNz(s.archiveLagH) > 2 ? "var(--warn)" : _tabsNz(s.archiveLagH) > 0 ? "var(--fg-2)" : "var(--muted)" }}>
                        {_tabsNz(s.archiveLagH) > 0 ? `${s.archiveLagH}h` : "—"}
                      </td>
                      <td>
                        {s.raid && s.raid !== "unknown"
                          ? <span className={"state-pill " + (s.raid === "ok" ? "ok" : s.raid === "err" ? "err" : "warn")}>{s.raid}</span>
                          : <span style={{ color: "var(--muted)" }}>—</span>}
                      </td>
                      <td className="mono" style={{ textAlign: "right", color: "var(--muted)" }}>{_tabsNz(s.uptimeD)}d</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>}
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────
// ALARMS
// ─────────────────────────────────────────────────────────────
const NvrTabAlarms = () => {
  const A = _tabsArr(window.VMS_ALARMS);
  const [sev, setSev] = useStateNVT("all");
  const [ack, setAck] = useStateNVT("all");
  const counts = {
    all:     A.length,
    high:    A.filter(a => a.sev === "high" || a.sev === "disaster").length,
    warning: A.filter(a => a.sev === "warning").length,
    info:    A.filter(a => a.sev === "info").length,
    unack:   A.filter(a => !a.ack).length,
    ack:     A.filter(a => a.ack).length
  };
  const rows = A.filter(a =>
    (sev === "all"
      || (sev === "high" && (a.sev === "high" || a.sev === "disaster"))
      || sev === a.sev)
    && (ack === "all" || (ack === "unack" ? !a.ack : a.ack))
  );

  return (
    <div className="tab-pane">
      <div className="card-h-bar">
        <span className="h-title">Active alarms · last 24h</span>
        <SourceBadge src="ext"/>
        <SourceBadge src="zbx"/>
        <div className="h-spacer"/>
        <div className="trig-filter">
          <span className={"tf " + (sev === "all" ? "active" : "")}     onClick={() => setSev("all")}>All <b>{counts.all}</b></span>
          <span className={"tf err " + (sev === "high" ? "active" : "")} onClick={() => setSev("high")}>High <b>{counts.high}</b></span>
          <span className={"tf warn " + (sev === "warning" ? "active" : "")} onClick={() => setSev("warning")}>Warning <b>{counts.warning}</b></span>
          <span className={"tf " + (sev === "info" ? "active" : "")}    onClick={() => setSev("info")}>Info <b>{counts.info}</b></span>
        </div>
        <span style={{ width: 8 }}/>
        <div className="trig-filter">
          <span className={"tf " + (ack === "all" ? "active" : "")}      onClick={() => setAck("all")}>Any <b>{counts.all}</b></span>
          <span className={"tf warn " + (ack === "unack" ? "active" : "")} onClick={() => setAck("unack")}>Unacked <b>{counts.unack}</b></span>
          <span className={"tf " + (ack === "ack" ? "active" : "")}      onClick={() => setAck("ack")}>Acked <b>{counts.ack}</b></span>
        </div>
      </div>

      <div className="card">
        {A.length === 0
          ? <div style={{ padding: 32, textAlign: "center", color: "var(--muted)" }}>No active alarms.</div>
          : <table className="link-tbl nvr-tbl alarm-tbl">
              <thead>
                <tr>
                  <th style={{ width: 130 }}>Timestamp</th>
                  <th style={{ width: 80 }}>Severity</th>
                  <th style={{ width: 200 }}>Object</th>
                  <th>Message</th>
                  <th style={{ width: 130 }}>Site</th>
                  <th style={{ width: 90 }}>State</th>
                  <th style={{ width: 160, textAlign: "right" }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a, i) => (
                  <tr key={i} className={a.ack ? "row-ack" : a.sev === "high" || a.sev === "disaster" ? "row-err" : a.sev === "warning" ? "row-warn" : ""}>
                    <td className="mono" style={{ fontSize: 11, color: "var(--muted)" }}>{a.ts}</td>
                    <td><Sev level={a.sev}/></td>
                    <td className="mono" style={{ color: "var(--accent)" }}>{a.cam || a.srv || "—"}</td>
                    <td style={{ color: "var(--fg-2)" }}>{a.msg}</td>
                    <td style={{ color: "var(--fg-2)", fontSize: 11 }}>{a.site}</td>
                    <td>{a.ack ? <span className="state-pill ok">acked</span> : <span className="state-pill warn">open</span>}</td>
                    <td style={{ textAlign: "right" }}>
                      <span className="row-action">{a.ack ? "View" : "Ack"}</span>
                      <span className="row-action">Suppress 1h</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>}
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────
// STORAGE
// ─────────────────────────────────────────────────────────────
const NvrTabStorage = () => {
  const M = Object.assign({
    storageTotalTB: 0, storageUsedTB: 0, retentionDays: 0,
    evidenceLockSlots: 0, evidenceLockUsed: 0
  }, window.MILESTONE || {});
  const S  = _tabsArr(window.SITES);
  const SR = _tabsArr(window.SERVERS).filter(s => s.role === "Recording Server");
  const usedTB  = _tabsNz(M.storageUsedTB);
  const totalTB = _tabsNz(M.storageTotalTB);
  const freeTB  = Math.max(0, totalTB - usedTB);
  const pct     = totalTB > 0 ? (usedTB / totalTB) * 100 : 0;
  const nearLimit = S.filter(s => _tabsNz(s.storageCapGB,1) && (s.storageGB / s.storageCapGB) > 0.9).length;

  return (
    <div className="tab-pane">
      <div className="row" style={{ gridTemplateColumns: "1fr 1.5fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h"><h3>Fleet storage</h3><SourceBadge src="zbx"/></div>
          <div className="card-b" style={{ display: "flex", flexDirection: "column", gap: 18, alignItems: "center", padding: 20 }}>
            {totalTB > 0
              ? <Ring value={usedTB} max={totalTB} size={170}
                      color={pct > 90 ? "var(--err)" : pct > 80 ? "var(--warn)" : "var(--zbx)"}
                      label={`${pct.toFixed(0)}%`}
                      sub={`${usedTB.toFixed(1)} / ${totalTB.toFixed(0)} TB`}
                      threshold={totalTB * 0.9} />
              : <div style={{ color: "var(--muted)", padding: 30 }}>Storage capacity not yet templated.</div>}
            <div className="kv tight" style={{ width: "100%", borderTop: "1px solid var(--line)" }}>
              <div className="k">Used</div><div className="v">{usedTB.toFixed(1)} TB</div><div className="b"/>
              <div className="k">Free</div><div className="v" style={{ color: "var(--ok)" }}>{freeTB.toFixed(1)} TB</div><div className="b"/>
              <div className="k">Retention</div><div className="v">{M.retentionDays || "—"} {M.retentionDays ? "days standard" : ""}</div><div className="b"/>
              <div className="k">Evidence locks</div><div className="v">{M.evidenceLockUsed} / {M.evidenceLockSlots} active</div><div className="b"/>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Per-site capacity</h3>
            <SourceBadge src="zbx"/>
            <div className="h-spacer"/>
            <span className="h-meta">{nearLimit} approaching limit</span>
          </div>
          <div style={{ padding: 14, display: "flex", flexDirection: "column", gap: 14 }}>
            {S.length === 0
              ? <div style={{ color: "var(--muted)", padding: 12 }}>No sites discovered yet.</div>
              : S.map(s => {
                  const cap = _tabsNz(s.storageCapGB, 1);
                  const used = _tabsNz(s.storageGB);
                  const sitePct = cap > 0 ? (used / cap) * 100 : 0;
                  return (
                    <div key={s.name} className="storage-row">
                      <div className="sr-name">
                        <StatusDot state={sitePct > 90 ? "err" : sitePct > 80 ? "warn" : "ok"}/>
                        <span style={{ color: "var(--fg)", fontWeight: 500 }}>{s.name}</span>
                        <span className="mono" style={{ color: "var(--muted)", fontSize: 10.5 }}>{s.server}</span>
                      </div>
                      <div className="storage-bar compact" style={{ flex: 1 }}>
                        <div className="label-row">
                          <span className="name mono">{(used/1000).toFixed(1)} / {(cap/1000).toFixed(0)} TB</span>
                          <span className="pct">{sitePct.toFixed(0)}%</span>
                        </div>
                        <div className="track"><div className={"fill " + (sitePct > 90 ? "err" : sitePct > 80 ? "warn" : "")} style={{ width: `${sitePct}%` }}/></div>
                      </div>
                      <div className="sr-retention mono">{M.retentionDays ? `${M.retentionDays}d` : "—"}</div>
                    </div>
                  );
                })}
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Storage volumes</h3>
          <SourceBadge src="zbx"/>
          <div className="h-spacer"/>
          <span className="h-meta">per recording server</span>
        </div>
        {SR.length === 0
          ? <div style={{ padding: 32, textAlign: "center", color: "var(--muted)" }}>No recording-server volumes discovered.</div>
          : <table className="link-tbl nvr-tbl">
              <thead>
                <tr>
                  <th style={{ width: 20 }}></th>
                  <th>Recording server</th>
                  <th>Site</th>
                  <th style={{ width: 80, textAlign: "right" }}>Used %</th>
                  <th style={{ width: 200 }}>Utilisation</th>
                  <th style={{ width: 90 }}>RAID</th>
                  <th style={{ width: 90, textAlign: "right" }}>Archive lag</th>
                </tr>
              </thead>
              <tbody>
                {SR.map(s => {
                  const disk = _tabsNz(s.disk);
                  return (
                    <tr key={s.id}>
                      <td><StatusDot state={s.raid === "err" ? "err" : (disk > 90 ? "warn" : s.raid === "warn" ? "warn" : "ok")}/></td>
                      <td className="mono" style={{ color: "var(--accent)" }}>{s.id}</td>
                      <td style={{ color: "var(--fg-2)" }}>{s.site}</td>
                      <td className="mono" style={{ textAlign: "right" }}>{disk ? `${disk.toFixed(0)}%` : "—"}</td>
                      <td>{disk ? <InlineBar v={disk} max={100} warn={80} crit={90} unit="%" /> : <span style={{ color: "var(--muted)" }}>—</span>}</td>
                      <td>
                        {s.raid && s.raid !== "unknown"
                          ? <span className={"state-pill " + (s.raid === "ok" ? "ok" : s.raid === "err" ? "err" : "warn")}>{s.raid}</span>
                          : <span style={{ color: "var(--muted)" }}>—</span>}
                      </td>
                      <td className="mono" style={{ textAlign: "right", color: _tabsNz(s.archiveLagH) > 2 ? "var(--warn)" : "var(--muted)" }}>
                        {_tabsNz(s.archiveLagH) > 0 ? `${s.archiveLagH}h` : "—"}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>}
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────
// EVIDENCE LOCK
// ─────────────────────────────────────────────────────────────
const NvrTabEvidence = () => {
  const E = _tabsArr(window.EVIDENCE_LOCKS);
  const M = Object.assign({ evidenceLockSlots: 0, evidenceLockUsed: 0 }, window.MILESTONE || {});

  if (E.length === 0) {
    return (
      <div className="tab-pane">
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="stat-grid">
            <div className="stat-cell">
              <div className="lbl">Active evidence locks <SourceBadge src="ext"/></div>
              <div className="val">{M.evidenceLockUsed}<span className="u">/ {M.evidenceLockSlots}</span></div>
              <div className="sub">{Math.max(0, M.evidenceLockSlots - M.evidenceLockUsed)} slots available</div>
            </div>
          </div>
        </div>
        <_TabEmpty>
          Per-lock detail isn't templated yet — only the slot counter
          from the XProtect license is available. Wire the
          /api/rest/v1/evidence endpoint to populate this view.
        </_TabEmpty>
      </div>
    );
  }

  const totalGB = E.reduce((a, e) => a + _tabsNz(e.sizeGB), 0);
  const now = new Date();
  const expiring = E.filter(e => {
    const d = Date.parse(e.expires);
    if (Number.isNaN(d)) return false;
    return (d - now.getTime()) < 30 * 86400 * 1000;
  }).length;

  return (
    <div className="tab-pane">
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="stat-grid">
          <div className="stat-cell">
            <div className="lbl">Active evidence locks <SourceBadge src="ext"/></div>
            <div className="val">{M.evidenceLockUsed}<span className="u">/ {M.evidenceLockSlots}</span></div>
            <div className="sub">{Math.max(0, M.evidenceLockSlots - M.evidenceLockUsed)} slots available</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Locked footage</div>
            <div className="val">{totalGB.toFixed(1)}<span className="u">GB</span></div>
            <div className="sub">excluded from retention rollover</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Expiring &lt; 30d</div>
            <div className="val" style={{ color: expiring > 0 ? "var(--warn)" : "var(--ok)" }}>{expiring}</div>
            <div className="sub warn">review before auto-release</div>
          </div>
          <div className="stat-cell">
            <div className="lbl">Open cases</div>
            <div className="val">{new Set(E.map(e => e.case)).size}</div>
            <div className="sub">{E.filter(e => (e.case || "").startsWith("TPD")).length} TPD · {E.filter(e => (e.case || "").startsWith("TCS")).length} internal</div>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Evidence locks</h3>
          <SourceBadge src="ext"/>
          <div className="h-spacer"/>
          <span className="h-meta">XProtect Evidence Lock · sorted by creation date</span>
        </div>
        <div className="ev-grid">
          {E.map(e => {
            const expMs = Date.parse(e.expires);
            const daysLeft = Number.isNaN(expMs) ? null : Math.round((expMs - now.getTime()) / (86400 * 1000));
            const urgent = daysLeft !== null && daysLeft < 30;
            const caseStr = e.case || "";
            return (
              <div key={e.id} className={"ev-card " + (urgent ? "urgent" : "")}>
                <div className="ev-head">
                  <div className="ev-id mono">{e.id}</div>
                  <div className={"ev-case " + (caseStr.startsWith("TPD") ? "ext" : "int")}>{caseStr}</div>
                </div>
                <div className="ev-reason">{e.reason}</div>
                <div className="ev-kvs">
                  <div className="ev-kv"><span>Cameras</span><b className="mono">{Array.isArray(e.cams) ? e.cams.join(", ") : "—"}</b></div>
                  <div className="ev-kv"><span>Site</span><b>{e.site || "—"}</b></div>
                  <div className="ev-kv"><span>Footage</span><b className="mono">{e.start} → {e.end}</b></div>
                  <div className="ev-kv"><span>Size</span><b className="mono">{_tabsNz(e.sizeGB).toFixed(1)} GB</b></div>
                  <div className="ev-kv"><span>Locked by</span><b>{e.by || "—"}</b></div>
                </div>
                <div className="ev-foot">
                  <div className="ev-expire">
                    <span className="lbl">Expires</span>
                    <span className={"mono v " + (urgent ? "warn" : "")}>{e.expires}</span>
                    {daysLeft !== null && (
                      <span className={"days " + (urgent ? "warn" : "")}>{daysLeft >= 0 ? `${daysLeft}d` : `${-daysLeft}d ago`}</span>
                    )}
                  </div>
                  <div className="ev-actions">
                    <span className="row-action">Extend</span>
                    <span className="row-action">Export</span>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

window.NvrTabSites    = NvrTabSites;
window.NvrTabCameras  = NvrTabCameras;
window.NvrTabServers  = NvrTabServers;
window.NvrTabAlarms   = NvrTabAlarms;
window.NvrTabStorage  = NvrTabStorage;
window.NvrTabEvidence = NvrTabEvidence;

// Live tab badges. Counts re-read window globals at render time so
// the count updates with the 30s bridge poll.
const _liveBadge = (n, kind = "") => (n > 0 ? { v: n.toLocaleString(), kind } : null);
Object.defineProperty(window, "NVR_TABS", {
  configurable: true,
  get() {
    const sites = _tabsArr(window.SITES).length;
    const cams  = _tabsArr(window.CAMERAS).length;
    const srvs  = _tabsArr(window.SERVERS).length;
    const alarms = _tabsArr(window.VMS_ALARMS).length;
    return [
      { id: "overview", label: "Overview",          badge: null },
      { id: "sites",    label: "Sites",             badge: _liveBadge(sites) },
      { id: "cameras",  label: "Cameras",           badge: _liveBadge(cams) },
      { id: "servers",  label: "Recording Servers", badge: _liveBadge(srvs) },
      { id: "alarms",   label: "Alarms",            badge: _liveBadge(alarms, "warn") },
      { id: "storage",  label: "Storage",           badge: null },
      { id: "evidence", label: "Evidence Lock",     badge: _liveBadge(_tabsArr(window.EVIDENCE_LOCKS).length) }
    ];
  }
});
