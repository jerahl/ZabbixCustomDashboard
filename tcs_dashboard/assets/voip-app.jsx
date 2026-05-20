// VoIP / 3CX monitoring dashboard
// Single-page Zabbix-style view of the TCS 3CX phone system.

const { useState: useStateVP, useEffect: useEffectVP, useMemo: useMemoVP } = React;

// ═══════════════════════════════════════════════════════════════
// DATA — fictional, modeled on a typical school-district 3CX v20 deploy
// ═══════════════════════════════════════════════════════════════

// 24h concurrent-calls history (15-min buckets, 96 samples)
const _concur24h = (() => {
  // school-day shape: ramp 7am, peak ~10-11 and 1-2pm, drop after 4pm
  const out = [];
  for (let i = 0; i < 96; i++) {
    const hr = i / 4;
    let base;
    if (hr < 6) base = 2 + Math.sin(hr) * 1;
    else if (hr < 7.5) base = 4 + (hr - 6) * 6;
    else if (hr < 11) base = 22 + (hr - 7.5) * 5 + Math.sin(hr * 2) * 4;
    else if (hr < 12) base = 38 - (hr - 11) * 6;
    else if (hr < 14) base = 32 + Math.sin((hr - 12) * 3) * 8;
    else if (hr < 16) base = 30 - (hr - 14) * 6;
    else if (hr < 18) base = 16 - (hr - 16) * 4;
    else base = 6 + Math.sin(hr) * 2;
    out.push(Math.max(0, Math.round(base + (i % 7) * 0.4)));
  }
  return out;
})();

const _inbound24h  = _concur24h.map((v, i) => Math.round(v * (0.55 + Math.sin(i * 0.3) * 0.05)));
const _outbound24h = _concur24h.map((v, i) => v - _inbound24h[i]);

window.VOIP_PBX = {
  fqdn: "pbx.tcs.local",
  ip: "10.10.5.20",
  version: "20.0 U4 (Build 4.2.0.1)",
  edition: "Enterprise · 256 SC",
  uptime: "47d 14h 22m",
  region: "Tuscaloosa · Main Data Center (Arc-DC)",
  activeNow: _concur24h[_concur24h.length - 1],
  capacity: 256,
  peakToday: Math.max(..._concur24h.slice(28)),
  callsToday: 4187,
  callsInbound: 2392,
  callsOutbound: 1411,
  callsInternal: 384,
  registeredExt: 198,
  totalExt: 214,
  avgMos: 4.32,
  asr: 96.4,  // answer-seizure ratio
  acd: "3m 41s", // average call duration
  history: { concur: _concur24h, inbound: _inbound24h, outbound: _outbound24h },
};

// Services (3CX components + supporting infra)
window.VOIP_SERVICES = [
  { name: "3CX Phone System",     status: "running", uptime: "47d", sub: "core call manager · v20 U4",        load: "42%"  },
  { name: "3CX Media Server",     status: "running", uptime: "47d", sub: "G.711, G.722, OPUS · 28 active",   load: "31%"  },
  { name: "3CX Web Server (Nginx)", status: "running", uptime: "47d", sub: "TLS 1.3 · sbc.tcs.org",          load: "9%"   },
  { name: "PostgreSQL 14",        status: "running", uptime: "47d", sub: "CDR · 2.4 GB · 14k rows/day",      load: "12%"  },
  { name: "3CX SBC · arc-sbc-01", status: "running", uptime: "12d", sub: "Arcadia DMZ · 5060/UDP, 5061/TLS", load: "ok"   },
  { name: "3CX SBC · bhs-sbc-01", status: "running", uptime: "9d 06h", sub: "Bryant DMZ · TLS",              load: "ok"   },
  { name: "3CX SBC · chs-sbc-01", status: "degraded", uptime: "2h 14m", sub: "Central DMZ · jitter > 25ms upstream", load: "warn" },
  { name: "RTP relay (proxy)",    status: "running", uptime: "47d", sub: "10000-20000/UDP",                  load: "ok"   },
  { name: "Voicemail / FAX2Email",status: "running", uptime: "47d", sub: "32 mailboxes · 84 new today",      load: "ok"   },
  { name: "Backup agent",         status: "running", uptime: "47d", sub: "Daily 02:00 → s3://tcs-pbx-bk",    load: "ok"   },
];

// SIP Trunks
window.VOIP_TRUNKS = [
  { name: "Bandwidth.com SIP — Main DID Block",   provider: "bandwidth.com", host: "siptrunk.bandwidth.com:5060", status: "reg", chTotal: 64, chIn: 14, chOut: 9, asr: 97.2, mos: 4.41, errors: 0, did: "+1 205-759-3500" },
  { name: "Bandwidth.com SIP — E911",             provider: "bandwidth.com", host: "e911.bandwidth.com:5060",     status: "reg", chTotal: 8,  chIn: 0,  chOut: 0, asr: 100,  mos: 4.50, errors: 0, did: "E911 only" },
  { name: "AT&T BVoIP — Failover PRI",            provider: "att.com",       host: "10.10.5.40 (Audiocodes)",     status: "reg", chTotal: 23, chIn: 2,  chOut: 1, asr: 95.8, mos: 4.28, errors: 0, did: "+1 205-507-2200" },
  { name: "Twilio Elastic — Outbound (campus calls)", provider: "twilio.com", host: "tcs.pstn.twilio.com:5061",   status: "reg", chTotal: 32, chIn: 0,  chOut: 7, asr: 94.1, mos: 4.36, errors: 0, did: "outbound" },
  { name: "Flowroute — Conf Bridges",             provider: "flowroute.com", host: "us-west-or.sip.flowroute.com",status: "dgr", chTotal: 16, chIn: 3,  chOut: 2, asr: 91.4, mos: 4.04, errors: 12, did: "conf · 5500-5599" },
  { name: "Internal — TCTA Legacy Avaya bridge",  provider: "internal",      host: "10.60.5.5:5060",              status: "unreg",chTotal: 8,  chIn: 0,  chOut: 0, asr: 0,    mos: 0,    errors: 47, did: "x6000-6099" },
];

// Live active calls (a snapshot — these are happening right now)
window.VOIP_CALLS = [
  { dir: "in",  from: "+1 205-759-3500",          fromSub: "Bandwidth · DID 3500",     to: "1042 — Auto-attendant",   toSub: "→ x1042 Reception",       dur: "0:14",  codec: "OPUS",   trunk: "BW-Main",  mos: 4.42, q: "good" },
  { dir: "in",  from: "+1 334-887-1102",          fromSub: "Parent · Montgomery, AL",  to: "1108 — J. Hartwell",      toSub: "Counseling · BHS",        dur: "2:41",  codec: "G.722",  trunk: "BW-Main",  mos: 4.38, q: "good" },
  { dir: "out", from: "1213 — A. Whitley",        fromSub: "Principal · CHS",          to: "+1 205-561-8893",         toSub: "Tuscaloosa City Hall",    dur: "11:08", codec: "G.711u", trunk: "Twilio",   mos: 4.21, q: "good" },
  { dir: "in",  from: "+1 205-242-9001",          fromSub: "Vendor · SchoolDude",      to: "1300 — Facilities Queue", toSub: "Hold 0:22 · 1 waiting",   dur: "0:54",  codec: "G.711u", trunk: "BW-Main",  mos: 4.34, q: "good" },
  { dir: "int", from: "1207 — Nurse (ARC)",       fromSub: "Arcadia ES",               to: "1402 — Admin Office",     toSub: "→ x1402",                 dur: "0:38",  codec: "G.722",  trunk: "internal", mos: 4.48, q: "good" },
  { dir: "in",  from: "+1 205-462-2210",          fromSub: "Unknown",                   to: "1500 — Conf Bridge",      toSub: "Flowroute · 5 in bridge", dur: "23:14", codec: "OPUS",   trunk: "Flowroute",mos: 3.81, q: "fair" },
  { dir: "out", from: "1108 — J. Hartwell",       fromSub: "Counseling · BHS",         to: "+1 334-844-1009",         toSub: "AL DHR · Tuscaloosa",     dur: "0:09",  codec: "G.711u", trunk: "Twilio",   mos: 4.40, q: "good" },
  { dir: "q",   from: "+1 205-345-7711",          fromSub: "Parent · Tuscaloosa",      to: "1300 — Facilities Queue", toSub: "Position 1 · waiting 0:08",dur: "0:08", codec: "—",      trunk: "BW-Main",  mos: 0,    q: "good" },
  { dir: "in",  from: "+1 256-555-2940",          fromSub: "Vendor · Apple Edu",        to: "1019 — IT Help Desk",     toSub: "→ x1019",                 dur: "4:22",  codec: "G.722",  trunk: "BW-Main",  mos: 3.42, q: "poor" },
  { dir: "int", from: "1018 — D. Brewer (IT)",    fromSub: "Tech Services · NRH",      to: "1002 — Network Ops",      toSub: "→ x1002",                 dur: "1:55",  codec: "G.722",  trunk: "internal", mos: 4.50, q: "good" },
];

// Top extensions by call count today
window.VOIP_TOP = [
  { ext: "1042", name: "Reception · Arc Admin",       site: "ARC", calls: 187, mins: 412, role: "front-desk" },
  { ext: "1300", name: "Facilities Help Queue",       site: "DIST", calls: 142, mins: 287, role: "queue" },
  { ext: "1019", name: "IT Help Desk Queue",          site: "DIST", calls: 118, mins: 614, role: "queue" },
  { ext: "1108", name: "J. Hartwell · Counseling",    site: "BHS", calls: 84,  mins: 198, role: "counsel" },
  { ext: "1207", name: "Nurse · Arcadia ES",          site: "ARC", calls: 61,  mins: 92,  role: "health" },
  { ext: "1402", name: "Admin Office · CHS",          site: "CHS", calls: 58,  mins: 132, role: "admin" },
];

// Queues (snapshot)
window.VOIP_QUEUES = [
  { name: "IT Help Desk",        ext: "1019", agents: 4, agentsOn: 3, waiting: 2, sla: 88, abandon: 4, ans: 116, slaSec: 30 },
  { name: "Facilities",          ext: "1300", agents: 3, agentsOn: 3, waiting: 1, sla: 94, abandon: 2, ans: 139, slaSec: 30 },
  { name: "Transportation",      ext: "1320", agents: 5, agentsOn: 4, waiting: 0, sla: 97, abandon: 1, ans: 71,  slaSec: 30 },
  { name: "Attendance · BHS",    ext: "1110", agents: 2, agentsOn: 2, waiting: 0, sla: 92, abandon: 3, ans: 54,  slaSec: 45 },
];

// Call quality 24h history (sample every 30min, 48 samples)
window.VOIP_QUALITY = {
  mos:    Array.from({length:48}, (_,i) => 4.3 + Math.sin(i*0.4)*0.08 + (i===24?-0.4:0) + (i===25?-0.3:0)),
  jitter: Array.from({length:48}, (_,i) => 6 + Math.abs(Math.sin(i*0.3))*4 + (i===24?22:0) + (i===25?14:0)),
  loss:   Array.from({length:48}, (_,i) => 0.05 + Math.abs(Math.sin(i*0.5))*0.3 + (i===24?1.4:0) + (i===25?0.8:0)),
  rtt:    Array.from({length:48}, (_,i) => 22 + Math.sin(i*0.25)*4 + (i===24?38:0)),
};

// Extensions — fictional list, grouped by site
function _genExt(site, base, count, opts={}) {
  let x = base * 7919;
  const rnd = () => { x = (x * 9301 + 49297) % 233280; return x / 233280; };
  const firstNames = ["A. Bates","J. Hartwell","R. Tate","P. Cobb","M. Lewis","S. Knox","D. Brewer","K. Pierce","L. Hayes","T. Ortiz","N. Frost","E. Marsh","C. Boyd","V. Yu","O. Park","H. Reeves","I. Garcia","B. Stokes","W. Lin","F. Akin","G. Dewey","Q. Mead","Z. Bell","Y. Cruz","X. Vega","U. Owen","R. Doss","P. Wade","M. Joffe","J. Cope","H. Voss"];
  const out = [];
  for (let i = 0; i < count; i++) {
    const r = rnd();
    let state;
    if (i === (opts.alertAt||-99)) state = "alert";
    else if (r < 0.08) state = "unreg";
    else if (r < 0.16) state = "call";
    else if (r < 0.20) state = "dnd";
    else state = "reg";
    const n = firstNames[Math.floor(rnd() * firstNames.length)];
    out.push({ ext: String(base + i), name: n, site, state });
  }
  return out;
}

window.VOIP_SITES = [
  { id: "ARC",  name: "Arcadia Elementary",       expanded: true,  ext: _genExt("ARC", 1200, 36, {alertAt: 11}) },
  { id: "BHS",  name: "Bryant High School",       expanded: true,  ext: _genExt("BHS", 1300, 56) },
  { id: "CHS",  name: "Central High School",      expanded: true,  ext: _genExt("CHS", 1400, 48, {alertAt: 22}) },
  { id: "NRH",  name: "Northridge High School",   expanded: true,  ext: _genExt("NRH", 1500, 32) },
  { id: "TCTA", name: "Tuscaloosa Career & Tech", expanded: true,  ext: _genExt("TCTA", 1600, 18) },
  { id: "DIST", name: "District Office & Queues", expanded: true,  ext: _genExt("DIST", 1000, 24) },
];

// Problems
window.VOIP_PROBLEMS = [
  { ts: "09:14:22", sev: "warning", host: "chs-sbc-01",   trig: "Upstream jitter > 25ms (Flowroute trunk)",      age: "00:24", ack: false },
  { ts: "08:42:08", sev: "high",    host: "TCTA-Avaya",   trig: "Internal SIP trunk x6000-6099 NOT REGISTERED",  age: "00:56", ack: false },
  { ts: "08:11:55", sev: "warning", host: "Flowroute",    trig: "12 SIP 503 errors in 5m on conf-bridge trunk",  age: "01:27", ack: false },
  { ts: "07:33:14", sev: "info",    host: "pbx.tcs.local",trig: "Daily CDR archive rotated · 14,217 rows",        age: "02:05", ack: true },
  { ts: "Yesterday",sev: "warning", host: "ext 1019",      trig: "Polycom VVX-450 firmware out of date (6.4.4)",  age: "16:30", ack: true },
];

// ═══════════════════════════════════════════════════════════════
// WIDGETS
// ═══════════════════════════════════════════════════════════════

// ── Concurrent-calls 24h area chart ──
const ConcurrencyChart = () => {
  const data = window.VOIP_PBX.history;
  const W = 720, H = 168, PAD_L = 30, PAD_R = 14, PAD_T = 14, PAD_B = 22;
  const innerW = W - PAD_L - PAD_R;
  const innerH = H - PAD_T - PAD_B;
  const max = 80;
  const n = data.concur.length;
  const x = i => PAD_L + (i / (n - 1)) * innerW;
  const y = v => PAD_T + innerH - Math.min(1, v / max) * innerH;
  const areaPath = (arr) => {
    const pts = arr.map((v, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(v)}`).join(" ");
    return `${pts} L${x(n-1)},${PAD_T + innerH} L${x(0)},${PAD_T + innerH} Z`;
  };
  const linePath = (arr) => arr.map((v, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(v)}`).join(" ");
  const ticks = [0, 20, 40, 60, 80];
  const hours = [0, 6, 9, 12, 15, 18, 23];

  return (
    <div className="card concur-card">
      <div className="card-h">
        <h3>Concurrent Calls · 24h</h3>
        <SourceBadge src="3cx" />
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">15-min buckets · live</span>
      </div>
      <div className="concur-meta">
        <div>
          <div className="cm-lbl">Active right now</div>
          <div className="cm-now">{window.VOIP_PBX.activeNow}<span className="u">/ {window.VOIP_PBX.capacity} SC</span></div>
        </div>
        <div className="cm-kv"><span className="lbl">Peak today</span><span className="v warn">{window.VOIP_PBX.peakToday} @ 10:45</span></div>
        <div className="cm-kv"><span className="lbl">Calls today</span><span className="v">{window.VOIP_PBX.callsToday.toLocaleString()}</span></div>
        <div className="cm-kv"><span className="lbl">ACD</span><span className="v">{window.VOIP_PBX.acd}</span></div>
        <div className="cm-kv"><span className="lbl">ASR</span><span className="v">{window.VOIP_PBX.asr}%</span></div>
        <div className="cm-spacer" />
        <div className="cm-cap"><b>{window.VOIP_PBX.callsInbound.toLocaleString()}</b> in · <b>{window.VOIP_PBX.callsOutbound.toLocaleString()}</b> out · <b>{window.VOIP_PBX.callsInternal}</b> internal</div>
      </div>
      <div className="concur-chart-wrap">
        <svg className="concur-svg" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none">
          {ticks.map(t => (
            <g key={t}>
              <line className="grid-line" x1={PAD_L} x2={W - PAD_R} y1={y(t)} y2={y(t)} />
              <text className="axis-lbl" x={PAD_L - 6} y={y(t) + 3} textAnchor="end">{t}</text>
            </g>
          ))}
          {/* peak threshold line */}
          <line className="peak-line" x1={PAD_L} x2={W - PAD_R} y1={y(window.VOIP_PBX.peakToday)} y2={y(window.VOIP_PBX.peakToday)} />
          {/* outbound area (lower) */}
          <path className="area-fill" fill="var(--info)" d={areaPath(data.outbound)} />
          {/* total area on top */}
          <path className="area-fill" fill="var(--cx)" d={areaPath(data.concur)} />
          <path className="area-line" stroke="var(--cx)" d={linePath(data.concur)} />
          <path className="area-line" stroke="var(--info)" strokeOpacity="0.7" d={linePath(data.outbound)} strokeDasharray="3 2" />
          {hours.map(h => (
            <text key={h} className="axis-lbl" x={PAD_L + (h/23) * innerW} y={H - 6} textAnchor="middle">{String(h).padStart(2,"0")}:00</text>
          ))}
        </svg>
      </div>
      <div className="concur-legend">
        <span className="item"><span className="sw" style={{background:"var(--cx)"}}></span> Total concurrent</span>
        <span className="item"><span className="sw" style={{background:"var(--info)",opacity:0.7}}></span> Outbound only</span>
        <span className="item"><span className="sw" style={{background:"var(--warn)",height:2,marginBottom:3}}></span> Today's peak ({window.VOIP_PBX.peakToday})</span>
      </div>
    </div>
  );
};

// ── Services / health panel ──
const ServicesPanel = () => (
  <div className="card">
    <div className="card-h">
      <h3>System Services</h3>
      <SourceBadge src="zbx" />
      <SourceBadge src="3cx" />
      <div className="h-spacer" />
      <span className="h-meta">{window.VOIP_PBX.uptime} up</span>
    </div>
    <div className="svc-list">
      {window.VOIP_SERVICES.map((s, i) => {
        const cls = s.status === "running" ? "" : (s.status === "degraded" ? "warn" : "err");
        const lbl = s.status === "running" ? "OK" : (s.status === "degraded" ? "DEGR" : "DOWN");
        return (
          <div key={i} className="svc-row">
            <span className={"svc-led " + cls}></span>
            <div>
              <div className="svc-name">{s.name}</div>
              <div className="svc-sub">{s.sub}</div>
            </div>
            <div className="svc-load">{typeof s.load === "string" && s.load.endsWith("%") ? s.load : ""}</div>
            <span className={"svc-pill " + cls}>{lbl}</span>
          </div>
        );
      })}
    </div>
    <div className="svc-foot">
      <div><div className="k">PBX FQDN</div><div className="v">{window.VOIP_PBX.fqdn}</div></div>
      <div><div className="k">License</div><div className="v">{window.VOIP_PBX.edition}</div></div>
      <div><div className="k">Version</div><div className="v">{window.VOIP_PBX.version}</div></div>
      <div><div className="k">Region</div><div className="v" style={{whiteSpace:"normal", lineHeight:1.3}}>{window.VOIP_PBX.region}</div></div>
    </div>
  </div>
);

// ── KPI strip across top ──
const VoipKpis = () => {
  const p = window.VOIP_PBX;
  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="swstat-strip">
        <div className="swstat-cell">
          <div className="lbl">Active Calls</div>
          <div className="val" style={{color:"var(--cx)"}}>{p.activeNow}<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> / {p.capacity}</span></div>
          <Sparkline data={p.history.concur.slice(-24)} color="var(--cx)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">Calls Today</div>
          <div className="val">{p.callsToday.toLocaleString()}</div>
          <div style={{fontSize:10,color:"var(--muted)",fontFamily:"var(--mono)"}}>{p.callsInbound.toLocaleString()} in · {p.callsOutbound.toLocaleString()} out</div>
        </div>
        <div className="swstat-cell">
          <div className="lbl">Registered Phones</div>
          <div className="val ok">{p.registeredExt}<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> / {p.totalExt}</span></div>
          <div style={{fontSize:10,color:"var(--warn)",fontFamily:"var(--mono)"}}>● {p.totalExt - p.registeredExt} unreg</div>
        </div>
        <div className="swstat-cell">
          <div className="lbl">Avg MOS · 1h</div>
          <div className="val ok">{p.avgMos.toFixed(2)}</div>
          <Sparkline data={window.VOIP_QUALITY.mos.slice(-24)} color="var(--ok)" width={120} height={20} />
        </div>
        <div className="swstat-cell">
          <div className="lbl">ASR (Answer)</div>
          <div className="val ok">{p.asr}%</div>
          <div style={{fontSize:10,color:"var(--muted)",fontFamily:"var(--mono)"}}>ACD {p.acd}</div>
        </div>
        <div className="swstat-cell">
          <div className="lbl">SIP Trunks</div>
          <div className="val warn">5<span style={{fontSize:11,color:"var(--muted)",fontWeight:500}}> / 6 up</span></div>
          <div style={{fontSize:10,color:"var(--err)",fontFamily:"var(--mono)"}}>● 1 unreg · 1 degraded</div>
        </div>
      </div>
    </div>
  );
};

// ── Trunks table ──
const TrunksCard = () => (
  <div className="card">
    <div className="card-h">
      <h3>SIP Trunks · Carriers</h3>
      <SourceBadge src="3cx" />
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <span className="h-meta">OPTIONS keepalive · 60s</span>
      <span className="h-link">Open in 3CX Mgmt <Icon name="external" size={11} /></span>
    </div>
    <table className="trunk-tbl">
      <thead>
        <tr>
          <th style={{width: 90}}>Status</th>
          <th>Trunk / Carrier</th>
          <th style={{width: 240}}>Channel utilization</th>
          <th style={{width: 70, textAlign:"right"}}>In</th>
          <th style={{width: 70, textAlign:"right"}}>Out</th>
          <th style={{width: 64, textAlign:"right"}}>ASR</th>
          <th style={{width: 60, textAlign:"right"}}>MOS</th>
          <th style={{width: 60, textAlign:"right"}}>Err 5m</th>
        </tr>
      </thead>
      <tbody>
        {window.VOIP_TRUNKS.map((t, i) => {
          const used = t.chIn + t.chOut;
          const freePct = ((t.chTotal - used) / t.chTotal) * 100;
          const inPct = (t.chIn / t.chTotal) * 100;
          const outPct = (t.chOut / t.chTotal) * 100;
          return (
            <tr key={i}>
              <td><span className={"tk-status " + t.status}>{t.status === "reg" ? "REG" : t.status === "dgr" ? "DEGR" : "UNREG"}</span></td>
              <td>
                <div className="tk-name">{t.name}</div>
                <div className="tk-host">{t.host} · {t.did}</div>
              </td>
              <td>
                <div className="ch-bar">
                  <i className="in"  style={{width: inPct + "%"}} />
                  <i className="out" style={{width: outPct + "%"}} />
                  <i className="free" style={{width: freePct + "%"}} />
                  <span className="lbl">{used}/{t.chTotal}</span>
                </div>
              </td>
              <td className="mono" style={{textAlign:"right", color:"var(--cx)"}}>{t.chIn}</td>
              <td className="mono" style={{textAlign:"right", color:"var(--info)"}}>{t.chOut}</td>
              <td className="mono" style={{textAlign:"right", color: t.asr === 0 ? "var(--muted)" : (t.asr < 92 ? "var(--warn)" : "var(--fg-2)")}}>
                {t.asr > 0 ? t.asr.toFixed(1) + "%" : "—"}
              </td>
              <td className="mono" style={{textAlign:"right", color: t.mos === 0 ? "var(--muted)" : (t.mos < 4.1 ? "var(--warn)" : "var(--ok)")}}>
                {t.mos > 0 ? t.mos.toFixed(2) : "—"}
              </td>
              <td className="mono" style={{textAlign:"right", color: t.errors > 0 ? "var(--warn)" : "var(--muted)"}}>{t.errors}</td>
            </tr>
          );
        })}
      </tbody>
    </table>
  </div>
);

// ── Active calls list ──
const ActiveCallsCard = () => {
  const dirLbl = { in: "INBOUND", out: "OUTBOUND", int: "INTERNAL", q: "QUEUED" };
  return (
    <div className="card">
      <div className="card-h">
        <h3>Active Calls · live</h3>
        <SourceBadge src="3cx" />
        <div className="h-spacer" />
        <span className="h-meta">{window.VOIP_CALLS.length} ongoing · 2s refresh</span>
      </div>
      <div className="calls-list">
        {window.VOIP_CALLS.map((c, i) => {
          const onBars = c.q === "good" ? 4 : c.q === "fair" ? 2 : 1;
          return (
            <div key={i} className="call-row">
              <span className={"c-dir " + c.dir}>{dirLbl[c.dir]}</span>
              <div className="c-leg">
                <div className="who">{c.from}</div>
                <div className="sub">{c.fromSub}</div>
              </div>
              <div className="c-leg">
                <div className="who">{c.to}</div>
                <div className="sub">{c.toSub}</div>
              </div>
              <div className="c-dur">{c.dur}</div>
              <div className="c-tech">
                <span><b>{c.codec}</b></span>
                <span>via {c.trunk}</span>
              </div>
              <div className={"c-q " + c.q}>
                {c.mos > 0 ? <span className={"mos " + c.q}>{c.mos.toFixed(2)}</span> : <span className="mos" style={{color:"var(--muted)"}}>—</span>}
                <span className="bars">
                  {[0,1,2,3].map(b => <i key={b} className={b < onBars ? "on" : ""} />)}
                </span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

// ── Call quality card ──
const CallQualityCard = () => {
  const q = window.VOIP_QUALITY;
  const mosNow = q.mos[q.mos.length - 1];
  const jitNow = q.jitter[q.jitter.length - 1];
  const lossNow = q.loss[q.loss.length - 1];
  const rttNow = q.rtt[q.rtt.length - 1];
  const cls = (good, fair, val, inv) => {
    if (inv) return val <= good ? "ok" : (val <= fair ? "warn" : "err");
    return val >= good ? "ok" : (val >= fair ? "warn" : "err");
  };
  return (
    <div className="card">
      <div className="card-h">
        <h3>Call Quality · 24h</h3>
        <SourceBadge src="3cx" />
        <div className="h-spacer" />
        <span className="h-meta">RTCP-XR · 30m</span>
      </div>
      <div className="cq-rows">
        <div className="cq-row">
          <div className="cq-lbl"><span className="name">MOS</span><span className="sub">target ≥ 4.0</span></div>
          <div className="cq-spark"><Sparkline data={q.mos} color="var(--ok)" width={300} height={32} threshold={4.0} /></div>
          <div className="cq-val"><div className={"v " + cls(4.2, 4.0, mosNow)}>{mosNow.toFixed(2)}</div><div className="u">score</div></div>
        </div>
        <div className="cq-row">
          <div className="cq-lbl"><span className="name">Jitter</span><span className="sub">target ≤ 20ms</span></div>
          <div className="cq-spark"><Sparkline data={q.jitter} color="var(--warn)" width={300} height={32} threshold={20} /></div>
          <div className="cq-val"><div className={"v " + cls(15, 20, jitNow, true)}>{jitNow.toFixed(1)}</div><div className="u">ms</div></div>
        </div>
        <div className="cq-row">
          <div className="cq-lbl"><span className="name">Packet loss</span><span className="sub">target ≤ 0.5%</span></div>
          <div className="cq-spark"><Sparkline data={q.loss} color="var(--pf)" width={300} height={32} threshold={0.5} /></div>
          <div className="cq-val"><div className={"v " + cls(0.3, 0.5, lossNow, true)}>{lossNow.toFixed(2)}</div><div className="u">%</div></div>
        </div>
        <div className="cq-row">
          <div className="cq-lbl"><span className="name">Round-trip</span><span className="sub">target ≤ 50ms</span></div>
          <div className="cq-spark"><Sparkline data={q.rtt} color="var(--info)" width={300} height={32} threshold={50} /></div>
          <div className="cq-val"><div className={"v " + cls(30, 50, rttNow, true)}>{rttNow.toFixed(0)}</div><div className="u">ms</div></div>
        </div>
      </div>
    </div>
  );
};

// ── Extension grid by site ──
const ExtensionGrid = () => {
  const [sites] = useStateVP(window.VOIP_SITES);
  const totals = useMemoVP(() => {
    const t = { reg:0, unreg:0, call:0, dnd:0, alert:0, total:0 };
    sites.forEach(s => s.ext.forEach(e => { t[e.state]++; t.total++; }));
    return t;
  }, [sites]);

  return (
    <div className="card ext-card">
      <div className="card-h">
        <h3>Extensions · Registration Status</h3>
        <SourceBadge src="3cx" />
        <SourceBadge src="pf" />
        <div className="h-spacer" />
        <span className="h-meta">{totals.total} extensions · last poll 8s</span>
      </div>
      <div className="ext-toolbar">
        <div className="legend">
          <span className="it"><span className="sw reg"></span> Registered ({totals.reg})</span>
          <span className="it"><span className="sw call"></span> On call ({totals.call})</span>
          <span className="it"><span className="sw dnd"></span> DND ({totals.dnd})</span>
          <span className="it"><span className="sw alert"></span> Alert ({totals.alert})</span>
          <span className="it"><span className="sw unreg"></span> Unregistered ({totals.unreg})</span>
        </div>
        <span className="spacer" />
        <span style={{fontFamily:"var(--mono)", fontSize:11, color:"var(--muted)"}}>filter:</span>
        <span style={{fontFamily:"var(--mono)", fontSize:11, color:"var(--fg-2)", background:"var(--bg-2)", border:"1px solid var(--line)", padding:"3px 8px", borderRadius:3}}>all sites</span>
      </div>
      {sites.map(site => {
        const counts = site.ext.reduce((a,e)=>{a[e.state]=(a[e.state]||0)+1;return a;},{});
        return (
          <div key={site.id} className="ext-site">
            <div className="ext-site-head">
              <span className="name">{site.name}</span>
              <span className="stat">
                <b className="ok">{counts.reg||0} reg</b> · <b className="ok">{counts.call||0} call</b>
                {counts.dnd ? <> · <b className="warn">{counts.dnd} dnd</b></> : null}
                {counts.alert ? <> · <b className="err">{counts.alert} alert</b></> : null}
                {counts.unreg ? <> · {counts.unreg} unreg</> : null}
                <span style={{marginLeft:8, color:"var(--muted-2)"}}>· {site.ext.length} total</span>
              </span>
            </div>
            <div className="ext-grid">
              {site.ext.map(e => (
                <div key={e.ext} className={"ext-cell " + e.state} title={`x${e.ext} · ${e.name} · ${e.state}`}>
                  <div className="ec-num">x{e.ext}</div>
                  <div className="ec-name">{e.name}</div>
                  <span className="ec-led" />
                </div>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
};

// ── Top extensions / talkers ──
const TopTalkers = () => {
  const max = Math.max(...window.VOIP_TOP.map(t => t.calls));
  return (
    <div className="card">
      <div className="card-h">
        <h3>Top Extensions · Today</h3>
        <SourceBadge src="3cx" />
        <div className="h-spacer" />
        <span className="h-meta">by call volume</span>
      </div>
      {window.VOIP_TOP.map((t, i) => (
        <div key={t.ext} className="tt-row">
          <span className="tt-rank">{i + 1}</span>
          <div className="tt-name">
            <div className="who"><span className="ext">x{t.ext}</span>{t.name}</div>
            <div className="sub">{t.mins} min talk · {t.site}</div>
          </div>
          <span className="tt-bar"><i style={{width: (t.calls/max*100) + "%"}} /></span>
          <span className="tt-cnt">{t.calls}</span>
        </div>
      ))}
    </div>
  );
};

// ── Queues panel ──
const QueuesCard = () => (
  <div className="card">
    <div className="card-h">
      <h3>Call Queues</h3>
      <SourceBadge src="3cx" />
      <div className="h-spacer" />
      <span className="h-meta">SLA = answered within target</span>
    </div>
    <div className="q-grid">
      {window.VOIP_QUEUES.map(q => (
        <div key={q.ext} className="q-cell">
          <div className="q-head">
            <span className="name">{q.name}</span>
            <span className="ext">x{q.ext}</span>
          </div>
          <div className="q-stats">
            <div className="q-stat"><span className="k">Agents</span><span className="v">{q.agentsOn}/{q.agents}</span></div>
            <div className="q-stat"><span className="k">Waiting</span><span className={"v " + (q.waiting>2?"warn":"")}>{q.waiting}</span></div>
            <div className="q-stat"><span className="k">SLA {q.slaSec}s</span><span className={"v " + (q.sla<90?"warn":"")}>{q.sla}%</span></div>
            <div className="q-stat"><span className="k">Abandon</span><span className={"v " + (q.abandon>3?"warn":"")}>{q.abandon}</span></div>
          </div>
          <div className="q-bar">
            <i className="ans" style={{width: (q.ans/(q.ans+q.abandon)*100) + "%"}}/>
            <i className="aban" style={{width: (q.abandon/(q.ans+q.abandon)*100) + "%"}}/>
          </div>
        </div>
      ))}
    </div>
  </div>
);

// ── Problems ──
const VoipProblems = () => (
  <div className="card">
    <div className="card-h">
      <h3>Problems</h3>
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <Icon name="filter" size={12} />
      <Icon name="more" size={14} />
    </div>
    <div style={{padding:"8px 14px 6px", fontSize:11, color:"var(--muted)", letterSpacing:0.4, textTransform:"uppercase", borderBottom:"1px solid var(--line)"}}>
      Triggers · last 24h · VoIP host group
    </div>
    {window.VOIP_PROBLEMS.map((p, i) => (
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
);

// ═══════════════════════════════════════════════════════════════
// APP
// ═══════════════════════════════════════════════════════════════

const TWEAK_DEFAULTS_VP = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#2bd6c0",
  "showSourceBadges": true,
  "showInternalCalls": true
}/*EDITMODE-END*/;

const VoipApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_VP);

  useEffectVP(() => {
    document.documentElement.style.setProperty("--cx", t.accent);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.showSourceBadges]);

  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;
  const p = window.VOIP_PBX;

  return (
    <div className="app" data-density={t.density} style={{ fontSize: `${13 * densityVar}px` }}>
      <GlobalSidebar active="voip" />
      <div className="main">
        <GlobalTopbar crumb={["Voice", "3CX Phone System", p.fqdn]} search="Find extension, DID, caller…" />
        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>3CX Phone System</h1>
              <span className="ip">{p.fqdn}</span>
              <span className="role-tag voip" style={{ fontSize: 10, padding: "1px 8px" }}>3CX · {p.version}</span>
            </div>
            <div className="host-meta voip-meta-bar">
              <span className="pill"><span className="dot" style={{ background: "var(--ok)" }} /> Phone System online</span>
              <span className="pill"><span className="lbl">IP</span> <span className="v">{p.ip}</span></span>
              <span className="pill"><span className="lbl">License</span> <span className="v">{p.edition}</span></span>
              <span className="pill"><span className="lbl">Uptime</span> <span className="v">{p.uptime}</span></span>
              <span className="pill"><span className="lbl">Region</span> <span className="v">Arc-DC</span></span>
              <span className="pill"><span className="dot" style={{ background: "var(--warn)" }} /> 1 trunk degraded · 1 unreg</span>
            </div>
          </div>
          <div className="timerange">
            <Icon name="calendar" />
            <span className="range-val">May 13 09:42 — May 14 09:42</span>
            <Icon name="chevron" />
          </div>
        </div>

        <div className="body" data-screen-label="VoIP Dashboard">
          <DemoBanner name="VoIP · 3CX Dashboard" />
          <VoipKpis />

          <div className="voip-row-2col" style={{ marginBottom: 14 }}>
            <div className="voip-stack">
              <ConcurrencyChart />
              <CallQualityCard />
            </div>
            <ServicesPanel />
          </div>

          <div style={{ marginBottom: 14 }}>
            <TrunksCard />
          </div>

          <div style={{ marginBottom: 14 }}>
            <ActiveCallsCard />
          </div>

          <div className="voip-row-2col-wide" style={{ marginBottom: 14 }}>
            <QueuesCard />
            <TopTalkers />
          </div>

          <div style={{ marginBottom: 14 }}>
            <ExtensionGrid />
          </div>

          <VoipProblems />
        </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Layout">
          <TweakRadio label="Density" value={t.density}
            options={[{value:"spacious",label:"Spacious"},{value:"balanced",label:"Balanced"},{value:"dense",label:"Dense"}]}
            onChange={v => setTweak("density", v)} />
        </TweakSection>
        <TweakSection title="Visual">
          <TweakColor label="3CX accent" value={t.accent}
            options={["#2bd6c0","#34d399","#5b8cff","#7c5cff","#f5b300","#d92929"]}
            onChange={v => setTweak("accent", v)} />
          <TweakToggle label="Show data-source badges" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<VoipApp />);
