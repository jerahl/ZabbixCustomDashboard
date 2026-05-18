// Per-tab views

const SectionTitle = ({ children, src }) => (
  <h2 className="section-title">
    {children}
    {src && <SourceBadge src={src} />}
  </h2>
);

// Helpers: per-AP SNMP uplink items are in bits/sec — convert to Mbps for
// display on the Live Telemetry strip. Null-safe.
const bpsToMbps = (v) => (typeof v === "number" ? +(v / 1e6).toFixed(2) : v);
const histToMbps = (h) => Array.isArray(h) ? h.map(v => v / 1e6) : h;

// ───────── Overview tab ─────────
const OverviewTab = ({ density }) => {
  const A = window.ALERTS_SUMMARY || {};
  const I = window.ZBX_ITEMS || {};
  const host = window.ZBX_HOST || {};

  // Derive an "issue" tone from a count — anything > 0 is a warning unless
  // a separate severity hint is provided.
  const toneFor = (n, warnAt = 1, errAt = 5) =>
    n >= errAt ? "err" : n >= warnAt ? "warn" : "ok";
  const iconFor = (n) => (n > 0 ? "alert" : "check");

  const cpu     = I.cpu     || {};
  const memory  = I.memory  || {};
  const pktLoss = I.pktLoss || {};

  const totalClients = (typeof A.totalClients === "number" && A.totalClients > 0)
    ? A.totalClients : (host.clients ?? 0);

  // Packet-loss live value drives the big tile: <1% ok, <5% warn, else err.
  const lossPct = typeof pktLoss.value === "number" ? pktLoss.value : null;
  const lossEvents = typeof A.packetLoss === "number" ? A.packetLoss : 0;
  const lossTone = lossPct === null ? "muted"
                 : lossPct >= 5 ? "err"
                 : lossPct >= 1 ? "warn"
                 : "ok";

  return (
    <div className="overview">
      {/* Health rings + Connectivity issues + Excessive packet loss */}
      <div className="row" style={{ gridTemplateColumns: "1.4fr 1fr .9fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h">
            <h3>Device Health</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">polling every 60s · template Extreme AP via SNMPv3</span>
          </div>
          <div className="health-grid" style={{ gridTemplateColumns: "repeat(2, 1fr)" }}>
            <HealthRing
              label="CPU Usage"
              value={cpu.value}
              color={cpu.trigger != null && cpu.value > cpu.trigger ? "var(--warn)" : "var(--zbx)"}
              sub={cpu.prev != null ? `prev ${cpu.prev}%` : "no history"}
            />
            <HealthRing
              label="Memory Usage"
              value={memory.value}
              color="var(--info)"
              sub={memory.history && memory.history.length ? `peak ${Math.max(...memory.history).toFixed(0)}%` : "no history"}
            />
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Connectivity Issues</h3>
            <SourceBadge src="zbx" />
            <SourceBadge src="pf" />
            <div className="h-spacer" />
            <span className="h-meta">Total Clients: <b style={{ color: "var(--fg)" }}>{totalClients.toLocaleString()}</b></span>
          </div>
          <div className="issues">
            <Issue n={A.associationFailures ?? 0} label="Association Failures"    tone={toneFor(A.associationFailures ?? 0)} icon={iconFor(A.associationFailures ?? 0)} />
            <Issue n={A.authFailures        ?? 0} label="Authentication Failures" tone={toneFor(A.authFailures ?? 0)}        icon={iconFor(A.authFailures ?? 0)} />
            <Issue n={A.networkIssues       ?? 0} label="Network Issues"          tone={toneFor(A.networkIssues ?? 0)}       icon={iconFor(A.networkIssues ?? 0)} />
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>Packet Loss</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">ICMP · last 5m</span>
          </div>
          <div className="issues" style={{ gridTemplateColumns: "1fr" }}>
            <Issue
              n={lossPct === null ? "—" : `${lossPct.toFixed(1)}%`}
              label={lossEvents > 0 ? `${lossEvents} loss event${lossEvents === 1 ? "" : "s"} (24h)` : "Loss rate (now)"}
              tone={lossTone === "muted" ? "ok" : lossTone}
              icon={lossTone === "ok" || lossTone === "muted" ? "check" : "alert"}
              big
            />
          </div>
        </div>
      </div>

      {/* Live throughput + radios */}
      <div className="row" style={{ gridTemplateColumns: "1fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h">
            <h3>Live Telemetry</h3>
            <SourceBadge src="zbx" />
            <div className="h-spacer" />
            <span className="h-meta">last 24h · {((I.uplinkIn && I.uplinkIn.history) || []).length} samples</span>
            <span className="h-link">Open in Grafana <Icon name="external" size={11} /></span>
          </div>
          <div className="spark-strip">
            <SparkCell label="Uplink In"  value={bpsToMbps((I.uplinkIn || {}).value)}  unit="Mbps" data={histToMbps((I.uplinkIn || {}).history)}  color="var(--zbx)" />
            <SparkCell label="Uplink Out" value={bpsToMbps((I.uplinkOut|| {}).value)}  unit="Mbps" data={histToMbps((I.uplinkOut|| {}).history)}  color="var(--info)" />
            <SparkCell label="Latency"    value={(I.latency || {}).value}              unit="ms"   data={(I.latency || {}).history}              color="var(--ok)" />
            <SparkCell label="Pkt Loss"   value={(I.pktLoss || {}).value}              unit="%"    data={(I.pktLoss || {}).history}              color="var(--warn)" />
          </div>
          <div className="spark-strip" style={{ borderTop: "1px solid var(--line)" }}>
            <SparkCell label="Noise 2.4 GHz" value={(I.noise24 || {}).value} unit="dBm" data={(I.noise24 || {}).history} color="var(--info)" />
            <SparkCell label="Noise 5 GHz"   value={(I.noise5  || {}).value} unit="dBm" data={(I.noise5  || {}).history} color="var(--info)" />
            <SparkCell label="TX Power 2.4"  value={(I.txpower24 || {}).value} unit="dBm" data={(I.txpower24 || {}).history} color="var(--pf)" />
            <SparkCell label="TX Power 5"    value={(I.txpower5  || {}).value} unit="dBm" data={(I.txpower5  || {}).history} color="var(--pf)" />
          </div>
        </div>
      </div>

      {/* System Info + Network Info */}
      <div className="row" style={{ gridTemplateColumns: "1fr 1fr", marginBottom: 14 }}>
        <div className="card">
          <div className="card-h"><h3>System Information</h3><div className="h-spacer" /><span className="h-meta">merged from Zabbix host + ExtremeCloud IQ</span></div>
          <div className="kv">
            {window.SYSTEM_INFO.map(([k, v, src]) => (
              <React.Fragment key={k}>
                <div className="k">{k}</div>
                <div className="v">{v}</div>
                <div className="b"><SourceBadge src={src} /></div>
              </React.Fragment>
            ))}
          </div>
        </div>

        <div className="card">
          <div className="card-h"><h3>Network Information</h3><div className="h-spacer" /><span className="h-meta">{(window.ZBX_HOST && window.ZBX_HOST.ip) ? `SNMPv3 · ${window.ZBX_HOST.ip}` : "SNMPv3"}</span></div>
          <div className="kv">
            {window.NETWORK_INFO.map(([k, v, src]) => (
              <React.Fragment key={k}>
                <div className="k">{k}</div>
                <div className="v">
                  {k === "Device Status"
                    ? <><StatusDot state="ok" /> {v}</>
                    : v}
                </div>
                <div className="b"><SourceBadge src={src} /></div>
              </React.Fragment>
            ))}
          </div>
        </div>
      </div>

      {/* Recent events tail */}
      <div className="card">
        <div className="card-h">
          <h3>Recent Events</h3>
          <div className="h-spacer" />
          <span className="h-meta">live merge: Zabbix triggers + PacketFence audit</span>
          <span className="h-link">Open events log <Icon name="external" size={11} /></span>
        </div>
        <div className="events">
          {window.ZBX_EVENTS.slice(0, 6).map((e, i) => (
            <div className="event" key={i}>
              <div className="ts">{e.ts}</div>
              <div className={`src ${e.source === "Zabbix" ? "zbx" : "pf"}`}>{e.source === "Zabbix" ? "ZBX" : "PF"}</div>
              <Sev level={e.severity} />
              <div className="msg">{e.msg} <span className="obj">· {e.obj}</span></div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

const HealthRing = ({ label, value, color, sub, max = 100, unit = "%" }) => {
  const missing = value === null || value === undefined || (typeof value === "number" && Number.isNaN(value));
  const v = missing ? 0 : value;
  const display = missing
    ? "—"
    : `${typeof v === "number" ? (Number.isInteger(v) ? v : v.toFixed(1)) : v}${unit === "%" ? "%" : ""}`;
  return (
    <div className="health-cell">
      <Ring value={v} max={max} color={missing ? "var(--muted)" : color} label={display} sub={unit !== "%" && !missing ? unit : null} />
      <div className="h-label">{label}</div>
      {sub && <div className="h-sub" style={{ fontSize: 10, color: "var(--muted)", marginTop: 2 }}>{sub}</div>}
    </div>
  );
};

const Issue = ({ n, label, tone, icon, big }) => (
  <div className={`issue ${tone}`}>
    <div className="ico"><Icon name={icon} size={16} /></div>
    <div className="num" style={big ? { fontSize: 22 } : {}}>{n}</div>
    <div className="lbl">{label}</div>
  </div>
);

const SparkCell = ({ label, value, unit, data, color }) => {
  const missing = value === null || value === undefined || (typeof value === "number" && Number.isNaN(value));
  const display = missing
    ? "—"
    : (typeof value === "number" ? (Number.isInteger(value) ? value : value.toFixed(2)) : value);
  const hist = Array.isArray(data) ? data : [];
  return (
    <div className="spark-cell">
      <div className="lbl">{label}</div>
      <div className="val" style={missing ? { color: "var(--muted)" } : {}}>
        {display}{!missing && <span className="u">{unit}</span>}
      </div>
      {hist.length > 0 ? (
        <Sparkline data={hist} color={color} width={240} height={30} />
      ) : (
        <div style={{ height: 30, display: "flex", alignItems: "center", color: "var(--muted)", fontSize: 10 }}>no history</div>
      )}
    </div>
  );
};

// ───────── Wireless tab ─────────
const WirelessTab = () => {
  const I = window.ZBX_ITEMS || {};
  const host = window.ZBX_HOST || {};
  return (
    <div className="row" style={{ gridTemplateColumns: "1fr 1fr", gap: 14 }}>
      <RadioCard band="2.4 GHz" channel={I.channel24} txpower={I.txpower24} noise={I.noise24} rxbytes={I.radioRx24} txbytes={I.radioTx24} />
      <RadioCard band="5 GHz"   channel={I.channel5}  txpower={I.txpower5}  noise={I.noise5}  rxbytes={I.radioRx5}  txbytes={I.radioTx5}  />
      <div className="card" style={{ gridColumn: "1 / -1" }}>
        <div className="card-h">
          <h3>SSIDs Broadcast</h3>
          <SourceBadge src="ext" />
          <div className="h-spacer"/>
          <span className="h-meta">
            {Array.isArray(window.SSIDS) && window.SSIDS.length > 0
              ? `${window.SSIDS.length} SSIDs · via XIQ policy`
              : "SSID inventory not yet wired — see ActionDashboard::collectSsids()"}
          </span>
        </div>
        {Array.isArray(window.SSIDS) && window.SSIDS.length > 0 ? (
          <table className="tbl">
            <thead><tr><th>SSID</th><th>VLAN</th><th>Auth</th><th>Encryption</th><th>Band</th><th>Clients</th><th>NAC Role</th></tr></thead>
            <tbody>
              {window.SSIDS.map(s => (
                <tr key={s.id || s.name}>
                  <td className="fg">{s.name}</td>
                  <td>{s.vlan ?? "—"}</td>
                  <td>{s.auth ?? "—"}</td>
                  <td>{s.encryption ?? "—"}</td>
                  <td>{s.band ?? "—"}</td>
                  <td>{s.clients ?? 0}</td>
                  <td>{s.role && <span className={`role-tag ${s.role.cls || "faculty"}`}>{s.role.label || s.role}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div style={{ padding: 30, textAlign: "center", color: "var(--muted)", fontSize: 12 }}>
            No SSID inventory available for this AP.
          </div>
        )}
      </div>
    </div>
  );
};

const RadioCard = ({ band, channel, txpower, noise, rxbytes, txbytes }) => {
  const ch = channel || {};
  const tp = txpower || {};
  const n  = noise   || {};
  const rx = rxbytes || {};
  const tx = txbytes || {};
  // Bytes/sec → Mbps for display.
  const bytesToMbps = (v) => (typeof v === "number" ? +(v * 8 / 1e6).toFixed(2) : v);
  return (
    <div className="card">
      <div className="card-h">
        <h3>Radio · {band}</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">
          ch {ch.value ?? "—"} · {tp.value != null ? `${tp.value} dBm TX` : "TX —"}
        </span>
      </div>
      <div className="card-b" style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
        <MiniMetric label="Channel"        v={ch.value}              unit=""    color="var(--pf)" />
        <MiniMetric label="TX Power"       v={tp.value}              unit="dBm" data={tp.history} color="var(--pf)" />
        <MiniMetric label="Noise Floor"    v={n.value}               unit="dBm" data={n.history}  color="var(--info)" />
        <MiniMetric label="RX Throughput"  v={bytesToMbps(rx.value)} unit="Mbps" data={(rx.history || []).map(v => v * 8 / 1e6)} color="var(--ok)" />
        <MiniMetric label="TX Throughput"  v={bytesToMbps(tx.value)} unit="Mbps" data={(tx.history || []).map(v => v * 8 / 1e6)} color="var(--zbx)" />
      </div>
    </div>
  );
};

const MiniMetric = ({ label, v, unit, data, color, threshold }) => {
  const missing = v === null || v === undefined || v === "" || (typeof v === "number" && Number.isNaN(v));
  const display = missing
    ? "—"
    : (typeof v === "number" ? (Number.isInteger(v) ? v.toString() : v.toFixed(1)) : String(v));
  return (
    <div style={{ background: "var(--bg-2)", borderRadius: 8, padding: 12, border: "1px solid var(--line)" }}>
      <div style={{ fontSize: 10, color: "var(--muted)", textTransform: "uppercase", letterSpacing: 0.5, marginBottom: 6 }}>{label}</div>
      <div style={{ fontFamily: "var(--mono)", fontSize: 20, fontWeight: 600, color: missing ? "var(--muted)" : "var(--fg)" }}>
        {display}{!missing && <span style={{ fontSize: 11, color: "var(--muted)", marginLeft: 3 }}>{unit}</span>}
      </div>
      {data && data.length > 0 && <Sparkline data={data} color={color} width={240} height={28} threshold={threshold} />}
    </div>
  );
};

// ───────── Wired tab ─────────
const WiredTab = () => (
  <div className="row" style={{ gridTemplateColumns: "1fr", gap: 14 }}>
    <div className="card">
      <div className="card-h"><h3>Wired Interfaces</h3><SourceBadge src="zbx" /><div className="h-spacer"/><span className="h-meta">SNMP IF-MIB poll · 30s</span></div>
      <table className="tbl">
        <thead><tr><th>Port</th><th>State</th><th>Speed/Duplex</th><th>In</th><th>Out</th><th>Errors</th><th>LLDP Neighbor</th></tr></thead>
        <tbody>
          {window.WIRED_PORTS.map(p => (
            <tr key={p.name}>
              <td className="fg">{p.name}</td>
              <td><StatusDot state={p.state}/> <span style={{textTransform:"uppercase"}}>{p.state}</span></td>
              <td>{p.speed} · {p.duplex}</td>
              <td>{p.in}</td>
              <td>{p.out}</td>
              <td>{p.err}</td>
              <td>{p.neighbor}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    <div className="card">
      <div className="card-h"><h3>PoE Power Budget</h3><SourceBadge src="zbx" /></div>
      <div className="card-b">
        <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 12 }}>
          <div style={{ fontFamily: "var(--mono)", fontSize: 28, fontWeight: 600 }}>12.4<span style={{ fontSize: 14, color: "var(--muted)" }}> / 25.5 W</span></div>
          <div style={{ flex: 1, background: "var(--bg-2)", borderRadius: 4, height: 8, overflow: "hidden", border: "1px solid var(--line)" }}>
            <div style={{ width: `${(12.4/25.5)*100}%`, height: "100%", background: "linear-gradient(90deg, var(--ok), var(--pf))" }} />
          </div>
          <div style={{ fontFamily: "var(--mono)", fontSize: 11, color: "var(--muted)" }}>49% of budget · Class 4 (802.3at)</div>
        </div>
      </div>
    </div>
  </div>
);

// ───────── Clients tab (PacketFence-driven) ─────────
const ClientsTab = ({ filter, setFilter }) => {
  const all = window.PF_CLIENTS;
  const filtered = all.filter(c => {
    if (filter === "all") return true;
    if (filter === "issues") return c.posture !== "compliant" && c.posture !== "n/a";
    if (filter === "students") return c.role.includes("Student");
    if (filter === "faculty") return c.role === "Faculty";
    if (filter === "guests") return c.role.includes("Guest");
    return true;
  });
  return (
    <div>
      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h">
          <h3>Connected Clients</h3>
          <SourceBadge src="pf" />
          <div className="h-spacer" />
          <div style={{ display: "flex", gap: 4 }}>
            {[["all","All"],["issues","Issues"],["students","Students"],["faculty","Faculty"],["guests","Guests"]].map(([k,l]) =>
              <button key={k} className={`btn sm ${filter===k?"primary":"ghost"}`} onClick={()=>setFilter(k)}>{l}</button>
            )}
          </div>
        </div>
        <table className="tbl">
          <thead>
            <tr>
              <th>Status</th>
              <th>MAC / Hostname</th>
              <th>User</th>
              <th>NAC Role</th>
              <th>VLAN</th>
              <th>SSID / Auth</th>
              <th>RSSI</th>
              <th>Rate</th>
              <th>OS</th>
              <th>Connected</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {filtered.map(c => <ClientRow key={c.mac} c={c} />)}
          </tbody>
        </table>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>Recent Authentication Failures</h3>
          <SourceBadge src="pf" />
          <div className="h-spacer" />
          <span className="h-meta">RADIUS audit · last 24h</span>
        </div>
        <table className="tbl">
          <thead><tr><th>Time</th><th>Client MAC</th><th>SSID</th><th>Reason</th><th>Attempts</th></tr></thead>
          <tbody>
            {window.PF_AUTH_FAILS.map((f, i) => (
              <tr key={i}>
                <td>{f.ts}</td>
                <td className="fg">{f.mac}</td>
                <td>{f.ssid}</td>
                <td style={{ color: "var(--warn)" }}>{f.reason}</td>
                <td>{f.attempts}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

const ClientRow = ({ c }) => {
  const roleClass = (() => {
    if (c.role === "Faculty") return "faculty";
    if (c.role.startsWith("Student-9-12")) return "student";
    if (c.role === "Student-BYOD") return "byod";
    if (c.role.includes("Guest")) return "guest";
    if (c.role === "AV-Equipment") return "av";
    if (c.role === "Quarantine") return "quarantine";
    return "unknown";
  })();
  const bars = c.rssi >= -55 ? 4 : c.rssi >= -65 ? 3 : c.rssi >= -75 ? 2 : 1;
  return (
    <tr>
      <td><StatusDot state={c.posture} /></td>
      <td><div className="fg">{c.host}</div><div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.mac}</div></td>
      <td>{c.user}</td>
      <td><span className={`role-tag ${roleClass}`}>{c.role}</span></td>
      <td>{c.vlan}</td>
      <td>{c.ssid}<div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.auth}</div></td>
      <td>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span className="rssi-bar">
            {[1,2,3,4].map(n => <i key={n} className={n <= bars ? "on" : ""} />)}
          </span>
          {c.rssi} dBm
        </div>
      </td>
      <td>{c.rate}<div style={{ color: "var(--muted)", fontSize: 10.5 }}>{c.band}</div></td>
      <td>{c.os}</td>
      <td>{c.since}</td>
      <td><Icon name="more" size={14}/></td>
    </tr>
  );
};

// ───────── Events tab ─────────
const EventsTab = () => (
  <div className="card">
    <div className="card-h">
      <h3>All Events</h3>
      <div className="h-spacer" />
      <span className="h-meta">unified Zabbix triggers + PacketFence audit log</span>
    </div>
    <div className="events">
      {window.ZBX_EVENTS.map((e, i) => (
        <div className="event" key={i}>
          <div className="ts">{e.ts}</div>
          <div className={`src ${e.source === "Zabbix" ? "zbx" : "pf"}`}>{e.source === "Zabbix" ? "ZBX" : "PF"}</div>
          <Sev level={e.severity} />
          <div className="msg">{e.msg} <span className="obj">· {e.obj}</span></div>
        </div>
      ))}
    </div>
  </div>
);

// ───────── Alerts tab ─────────
const AlertsTab = () => (
  <div className="row" style={{ gridTemplateColumns: "1fr 1fr", gap: 14 }}>
    <div className="card">
      <div className="card-h"><h3>Active Triggers</h3><SourceBadge src="zbx" /></div>
      <div style={{ padding: 30, textAlign: "center", color: "var(--muted)" }}>
        <Icon name="check" size={32} />
        <div style={{ marginTop: 8, fontSize: 14, color: "var(--ok)" }}>No active Zabbix triggers</div>
        <div style={{ fontSize: 11, marginTop: 4 }}>4 triggers monitored · last fired 10h ago</div>
      </div>
    </div>
    <div className="card">
      <div className="card-h"><h3>NAC Violations (24h)</h3><SourceBadge src="pf" /></div>
      <div style={{ padding: 18 }}>
        <div style={{ display: "flex", gap: 10, alignItems: "center", marginBottom: 10 }}>
          <div style={{ width: 8, height: 8, borderRadius: 50, background: "var(--warn)" }} />
          <div style={{ fontFamily: "var(--mono)", fontSize: 16, fontWeight: 600 }}>2 active</div>
          <div className="muted" style={{ fontSize: 11 }}>· 7 resolved</div>
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          <ViolationRow mac="F4:5C:89:0B:32:71" rule="OS version below policy" since="14m" action="Quarantine VLAN 666" />
          <ViolationRow mac="9C:8E:CD:11:B0:42" rule="Repeated EAP cert failures" since="14m" action="Auth blocked" />
        </div>
      </div>
    </div>
  </div>
);

const ViolationRow = ({ mac, rule, since, action }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: 10, background: "var(--bg-2)", borderRadius: 6, border: "1px solid var(--line)" }}>
    <Icon name="alert" size={14} />
    <div style={{ flex: 1 }}>
      <div className="mono" style={{ fontSize: 12 }}>{mac}</div>
      <div style={{ fontSize: 11, color: "var(--muted)" }}>{rule} · {action}</div>
    </div>
    <div className="mono muted" style={{ fontSize: 11 }}>{since}</div>
  </div>
);

window.OverviewTab = OverviewTab;
window.WirelessTab = WirelessTab;
window.WiredTab = WiredTab;
window.ClientsTab = ClientsTab;
window.EventsTab = EventsTab;
window.AlertsTab = AlertsTab;
