// Global Dashboard — high-level problem-area overview across all monitored estates
// Layout philosophy: triage > drill-down. Severity totals up top, sites heatmap +
// domain breakdown in the middle, triggers table + hotspots below, raw event stream
// at the bottom. Every card is something an operator can scan in <2 seconds.

const GlobalHeader = ({ now, timeRange, setTimeRange }) => (
  <div className="page-header" style={{ alignItems: "center" }}>
    <div style={{ flex: 1 }}>
      <div className="host-title">
        <h1>Global Dashboard</h1>
        <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>OPERATIONS · TIER-1</span>
      </div>
      <div className="host-meta">
        <span className="pill"><span className="dot" style={{ background: "var(--ok)" }} /> All proxies polling</span>
        <span className="pill"><span className="lbl">Last refresh</span> <span className="v">{now}</span></span>
        <span className="pill"><span className="lbl">Auto-refresh</span> <span className="v">30s</span></span>
        <span className="pill"><span className="lbl">Polled hosts</span> <span className="v">{GLOBAL_TOTALS.hosts.total.toLocaleString()}</span></span>
        <span className="pill"><span className="lbl">Templates</span> <span className="v">{GLOBAL_TOTALS.templates.version}</span></span>
      </div>
    </div>
    <div className="timerange">
      <Icon name="calendar" />
      <span className="range-val">{timeRange}</span>
      <Icon name="chevron" />
    </div>
  </div>
);

// ───────── Severity strip (Disaster / High / Warning / Info / Acknowledged / Hosts down) ─────────
const SeverityStrip = () => {
  const p = GLOBAL_TOTALS.problems;
  const cells = [
    { label: "Disaster",      value: p.disaster, color: "var(--err)",  bg: "rgba(242,95,92,0.10)",   note: `${p.disaster ? "active" : "—"}` },
    { label: "High",          value: p.high,     color: "var(--err)",  bg: "rgba(242,95,92,0.06)",   note: `${p.disaster + p.high} unack`  },
    { label: "Warning",       value: p.warning,  color: "var(--warn)", bg: "rgba(245,179,0,0.08)",   note: "+12 in 1h" },
    { label: "Info",          value: p.info,     color: "var(--info)", bg: "rgba(95,168,211,0.08)",  note: "drift" },
    { label: "Acknowledged",  value: p.ack,      color: "var(--fg-2)", bg: "var(--bg-2)",            note: `${Math.round(p.ack/(p.disaster+p.high+p.warning+p.info+p.ack)*100)}% of total` },
    { label: "Hosts down",    value: GLOBAL_TOTALS.hosts.down, color: "var(--err)", bg: "rgba(242,95,92,0.06)", note: `of ${GLOBAL_TOTALS.hosts.total.toLocaleString()}` },
  ];
  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="sev-strip">
        {cells.map(c => (
          <div className="sev-cell" key={c.label} style={{ background: c.bg }}>
            <div className="sev-cell-h">
              <span className="sev-cell-lbl" style={{ color: c.color }}>{c.label}</span>
              <SourceBadge src="zbx" />
            </div>
            <div className="sev-cell-v" style={{ color: c.color }}>{c.value}</div>
            <div className="sev-cell-note">{c.note}</div>
          </div>
        ))}
      </div>
    </div>
  );
};

// ───────── Problem trend strip (24h timeline of new problems) ─────────
const TrendStrip = () => {
  const data = window.PROBLEM_TIMELINE;
  const max = Math.max(...data);
  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h">
        <h3>New Problems (24h)</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">{data.reduce((a,b)=>a+b,0)} opened · {data[data.length-1]} active now</span>
      </div>
      <div className="trend-row">
        <div className="trend-bars">
          {data.map((v, i) => (
            <div key={i} className="trend-bar" title={`${i}:00 — ${v} new problems`}>
              <div className="trend-bar-fill" style={{ height: `${(v/max)*100}%`, background: i >= data.length - 4 ? "var(--err)" : "var(--zbx)" }} />
              {i % 4 === 0 && <div className="trend-bar-tick">{i.toString().padStart(2,"0")}</div>}
            </div>
          ))}
        </div>
        <div className="trend-side">
          <div className="trend-pill"><span className="dot" style={{ background: "var(--err)" }} /> Last 4h</div>
          <div className="trend-side-v">{data.slice(-4).reduce((a,b)=>a+b,0)}</div>
          <div className="trend-side-note">+62% vs prior 4h</div>
        </div>
      </div>
    </div>
  );
};

// ───────── Sites heatmap ─────────
const sevColors = {
  ok:       { bg: "rgba(52,211,153,0.12)",  bd: "rgba(52,211,153,0.35)", fg: "var(--ok)"  },
  info:     { bg: "rgba(95,168,211,0.12)",  bd: "rgba(95,168,211,0.35)", fg: "var(--info)" },
  warning:  { bg: "rgba(245,179,0,0.12)",   bd: "rgba(245,179,0,0.40)",  fg: "var(--warn)" },
  high:     { bg: "rgba(242,95,92,0.14)",   bd: "rgba(242,95,92,0.45)",  fg: "var(--err)"  },
  disaster: { bg: "rgba(242,95,92,0.30)",   bd: "var(--err)",            fg: "#ffd0cf"     },
};

const SitesHeatmap = ({ filter, setFilter }) => {
  const sites = filter === "issues"
    ? GLOBAL_SITES.filter(s => s.problems > 0)
    : filter === "ok"
    ? GLOBAL_SITES.filter(s => s.problems === 0)
    : GLOBAL_SITES;
  return (
    <div className="card">
      <div className="card-h">
        <h3>Sites — Health Map</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <div className="seg-toggle">
          {[["all", `All ${GLOBAL_SITES.length}`], ["issues", `Issues ${GLOBAL_SITES.filter(s=>s.problems).length}`], ["ok", `OK ${GLOBAL_SITES.filter(s=>!s.problems).length}`]].map(([k, l]) => (
            <button key={k} className={"seg-btn" + (filter === k ? " active" : "")} onClick={() => setFilter(k)}>{l}</button>
          ))}
        </div>
      </div>
      <div className="card-b">
        <div className="sites-grid">
          {sites.map(s => {
            const c = sevColors[s.sev] || sevColors.ok;
            return (
              <div
                key={s.id}
                className={"site-tile" + (s.kind === "outage" ? " pulse" : "")}
                style={{ background: c.bg, borderColor: c.bd }}
                title={`${s.name} · ${s.problems} problems · SLA ${s.sla}%`}
              >
                <div className="site-tile-h">
                  <span className="site-tile-id" style={{ color: c.fg }}>{s.id}</span>
                  {s.problems > 0 ? (
                    <span className="site-tile-prob" style={{ color: c.fg }}>{s.problems}</span>
                  ) : (
                    <Icon name="check" size={11} />
                  )}
                </div>
                <div className="site-tile-name">{s.name}</div>
                <div className="site-tile-meta">
                  <span>{s.hosts} hosts</span>
                  <span className="mono">{s.sla.toFixed(2)}%</span>
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <div className="sites-legend">
        {[["disaster","Disaster"],["high","High"],["warning","Warning"],["info","Info"],["ok","OK"]].map(([k,l]) => (
          <span className="legend-item" key={k}><span className="legend-sw" style={{ background: sevColors[k].bg, borderColor: sevColors[k].bd }} />{l}</span>
        ))}
        <span className="h-spacer" />
        <span className="legend-foot">{sites.reduce((n,s)=>n+s.problems,0)} problems · {sites.reduce((n,s)=>n+s.hosts,0).toLocaleString()} hosts shown</span>
      </div>
    </div>
  );
};

// ───────── Domain breakdown card ─────────
const DomainBreakdown = () => (
  <div className="card">
    <div className="card-h">
      <h3>Problems by Domain</h3>
      <SourceBadge src="zbx" />
      <div className="h-spacer" />
      <a className="h-link">All hosts <Icon name="external" size={11} /></a>
    </div>
    <div className="card-b tight">
      {GLOBAL_DOMAINS.map(d => {
        const total = d.ok + d.warn + d.err;
        const okPct   = (d.ok / total) * 100;
        const warnPct = (d.warn / total) * 100;
        const errPct  = (d.err / total) * 100;
        return (
          <a key={d.id} className="domain-row" href={d.href}>
            <div className="domain-icon"><Icon name={d.icon} size={16} /></div>
            <div className="domain-meta">
              <div className="domain-h">
                <span className="domain-label">{d.label}</span>
                <span className="domain-count">{d.total.toLocaleString()}</span>
                <span className="h-spacer" />
                <span className="domain-prob">
                  {d.problems} <span className="muted" style={{ fontSize: 10 }}>open</span>
                </span>
              </div>
              <div className="domain-bar">
                <div style={{ width: `${okPct}%`,   background: "var(--ok)"   }} />
                <div style={{ width: `${warnPct}%`, background: "var(--warn)" }} />
                <div style={{ width: `${errPct}%`,  background: "var(--err)"  }} />
              </div>
              <div className="domain-foot">
                <span className="domain-top" title={d.top}>↳ {d.top}</span>
                <Sparkline data={d.spark} color="var(--zbx)" width={84} height={20} fill={true} />
              </div>
            </div>
            <Icon name="chevron" size={14} />
          </a>
        );
      })}
    </div>
  </div>
);

// ───────── Active triggers table ─────────
const TriggersTable = ({ filterSev }) => {
  const rows = filterSev === "all" ? GLOBAL_TRIGGERS : GLOBAL_TRIGGERS.filter(t => t.sev === filterSev);
  return (
    <table className="tbl trig-tbl">
      <thead>
        <tr>
          <th style={{ width: 60 }}>Sev</th>
          <th style={{ width: 70 }}>Age</th>
          <th>Host / trigger</th>
          <th style={{ width: 60, textAlign: "center" }}>Site</th>
          <th style={{ width: 32 }}></th>
          <th style={{ width: 28 }}></th>
        </tr>
      </thead>
      <tbody>
        {rows.map((t, i) => (
          <tr key={i}>
            <td><Sev level={t.sev} /></td>
            <td className="mono">{t.age}</td>
            <td className="fg">
              <div style={{ display: "flex", flexDirection: "column", gap: 1 }}>
                <span className="mono" style={{ fontSize: 11, color: "var(--fg)" }}>{t.host}</span>
                <span style={{ fontFamily: "var(--sans)", fontSize: 11.5, color: "var(--fg-2)" }}>{t.trigger}</span>
              </div>
            </td>
            <td style={{ textAlign: "center" }}>
              <span className="site-chip">{t.site}</span>
            </td>
            <td><SourceBadge src={t.source} /></td>
            <td>
              {t.ack
                ? <Icon name="check" size={12} />
                : <span className="dot pulse-dot" style={{ background: "var(--err)" }} />}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
};

// ───────── Hotspots — top sites with most problems ─────────
const Hotspots = () => {
  const top = [...GLOBAL_SITES].sort((a, b) => b.problems - a.problems).slice(0, 6);
  const max = Math.max(...top.map(t => t.problems));
  return (
    <div className="hotspots">
      {top.map(s => {
        const c = sevColors[s.sev] || sevColors.ok;
        const pct = (s.problems / max) * 100;
        return (
          <div className="hotspot-row" key={s.id}>
            <div className="hotspot-id">{s.id}</div>
            <div className="hotspot-meta">
              <div className="hotspot-h">
                <span className="hotspot-name">{s.name}</span>
                <span className="hotspot-prob mono" style={{ color: c.fg }}>{s.problems}</span>
              </div>
              <div className="hotspot-bar">
                <div style={{ width: `${pct}%`, background: c.fg, opacity: 0.85 }} />
              </div>
              <div className="hotspot-foot">
                <span>{s.hosts} hosts</span>
                <span className="mono">SLA {s.sla.toFixed(2)}%</span>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
};

// ───────── Events stream ─────────
const EventsStream = () => (
  <div className="events">
    {GLOBAL_EVENTS.map((e, i) => (
      <div className="event" key={i}>
        <div className="ts">{e.ts}</div>
        <div className={"src " + e.source}>{e.source.toUpperCase()}</div>
        <div className="mono" style={{ fontSize: 11, color: "var(--fg-2)" }}>{e.host}</div>
        <div className="msg">
          <span style={{ color: e.sev === "ok" ? "var(--ok)" : e.sev === "high" || e.sev === "disaster" ? "var(--err)" : e.sev === "warning" ? "var(--warn)" : "var(--info)", fontWeight: 500 }}>
            {e.msg}
          </span>{" "}
          <span style={{ color: "var(--fg)" }}>{e.obj}</span>
        </div>
      </div>
    ))}
  </div>
);

// ───────── App ─────────
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "showSourceBadges": true,
  "siteFilter": "all",
  "sevFilter": "all",
  "groupBy": "site"
}/*EDITMODE-END*/;

const App = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [timeRange, setTimeRange] = React.useState("Last 24h");
  const [now, setNow] = React.useState("just now");

  React.useEffect(() => {
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.showSourceBadges]);

  return (
    <div className="app" data-density={t.density} data-screen-label="Global Dashboard">
      <GlobalSidebar active="global" />
      <div className="main">
        <GlobalTopbar crumb={["Tuscaloosa City Schools", "Operations", "Global"]} />
        <GlobalHeader now={now} timeRange={timeRange} setTimeRange={setTimeRange} />
        <div className="body">
          <SeverityStrip />
          <TrendStrip />

          <div className="row" style={{ gridTemplateColumns: "1.5fr 1fr", marginBottom: 14 }}>
            <SitesHeatmap filter={t.siteFilter} setFilter={v => setTweak("siteFilter", v)} />
            <DomainBreakdown />
          </div>

          <div className="row" style={{ gridTemplateColumns: "1.4fr 1fr", marginBottom: 14 }}>
            <div className="card">
              <div className="card-h">
                <h3>Active Triggers</h3>
                <SourceBadge src="zbx" />
                <div className="h-spacer" />
                <div className="seg-toggle">
                  {[["all","All"],["disaster","Disaster"],["high","High"],["warning","Warning"]].map(([k,l]) => (
                    <button key={k} className={"seg-btn" + (t.sevFilter === k ? " active" : "")} onClick={() => setTweak("sevFilter", k)}>{l}</button>
                  ))}
                </div>
                <a className="h-link">All <Icon name="external" size={11} /></a>
              </div>
              <div className="card-b tight" style={{ maxHeight: 380, overflowY: "auto" }}>
                <TriggersTable filterSev={t.sevFilter} />
              </div>
            </div>
            <div className="card">
              <div className="card-h">
                <h3>Top Problem Hotspots</h3>
                <SourceBadge src="zbx" />
                <div className="h-spacer" />
                <span className="h-meta">by site</span>
              </div>
              <div className="card-b">
                <Hotspots />
              </div>
            </div>
          </div>

          <div className="card">
            <div className="card-h">
              <h3>Recent Events</h3>
              <SourceBadge src="zbx" />
              <SourceBadge src="pf" />
              <SourceBadge src="ext" />
              <div className="h-spacer" />
              <a className="h-link">Open in event console <Icon name="external" size={11} /></a>
            </div>
            <div className="card-b tight">
              <EventsStream />
            </div>
          </div>
        </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Layout">
          <TweakRadio label="Density" value={t.density} options={[
            { value: "spacious", label: "Spacious" },
            { value: "balanced", label: "Balanced" },
            { value: "dense",    label: "Dense"    }
          ]} onChange={v => setTweak("density", v)} />
          <TweakToggle label="Show data-source badges (ZBX/PF/EXT)" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
        </TweakSection>
        <TweakSection title="Filters">
          <TweakRadio label="Sites view" value={t.siteFilter} options={[
            { value: "all",    label: "All"     },
            { value: "issues", label: "Issues"  },
            { value: "ok",     label: "OK only" },
          ]} onChange={v => setTweak("siteFilter", v)} />
          <TweakSelect label="Trigger severity" value={t.sevFilter} options={[
            { value: "all",      label: "All severities" },
            { value: "disaster", label: "Disaster only" },
            { value: "high",     label: "High & above" },
            { value: "warning",  label: "Warning only" },
          ]} onChange={v => setTweak("sevFilter", v)} />
        </TweakSection>
        <TweakSection title="Quick actions">
          <TweakButton onClick={() => setNow(new Date().toLocaleTimeString())}>Refresh now</TweakButton>
          <TweakButton onClick={() => alert("This would acknowledge all unacknowledged triggers below disaster.")}>Bulk-ack warnings</TweakButton>
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
