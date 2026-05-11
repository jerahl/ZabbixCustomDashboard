// Events console — windowed firing/resolved stream with metrics
// (rate, severity mix, MTTR, hourly timeline, top hosts).

const SEVERITIES_E = [
  { key: "disaster", label: "Disaster", color: "var(--err)"  },
  { key: "high",     label: "High",     color: "var(--err)"  },
  { key: "warning",  label: "Warning",  color: "var(--warn)" },
  { key: "info",     label: "Info",     color: "var(--info)" }
];

const RANGE_OPTIONS_E = [
  { key: "1h",  label: "Last 1h"  },
  { key: "6h",  label: "Last 6h"  },
  { key: "24h", label: "Last 24h" },
  { key: "7d",  label: "Last 7d"  }
];

const VALUE_OPTIONS = [
  { key: "any",      label: "All events"   },
  { key: "firing",   label: "Firing only"  },
  { key: "resolved", label: "Resolved only" }
];

const TWEAK_DEFAULTS_E = /*EDITMODE-BEGIN*/{
  density: "balanced",
  showSourceBadges: true
}/*EDITMODE-END*/;

const useEventsData = () => {
  const [tick, setTick] = React.useState(0);
  React.useEffect(() => {
    const onData = () => setTick(n => n + 1);
    window.addEventListener("tcs:events-data", onData);
    return () => window.removeEventListener("tcs:events-data", onData);
  }, []);
  return [window.EVENTS_DATA, tick];
};

const FilterBarE = ({ filters, groups, onChange }) => {
  const sevSet = new Set((filters.severity || "").split(",").filter(Boolean));
  const toggleSev = (key) => {
    if (sevSet.has(key)) sevSet.delete(key); else sevSet.add(key);
    onChange({ severity: Array.from(sevSet).join(",") });
  };
  return (
    <div className="filter-bar">
      <div className="filter-group">
        <label>Severity</label>
        <div className="chip-row">
          {SEVERITIES_E.map(s => (
            <button
              key={s.key}
              className={"chip" + (sevSet.has(s.key) ? " active" : "")}
              style={sevSet.has(s.key) ? { borderColor: s.color, color: s.color } : {}}
              onClick={() => toggleSev(s.key)}
            >{s.label}</button>
          ))}
        </div>
      </div>
      <div className="filter-group">
        <label>Type</label>
        <select value={filters.value || "any"} onChange={e => onChange({ value: e.target.value })}>
          {VALUE_OPTIONS.map(o => <option key={o.key} value={o.key}>{o.label}</option>)}
        </select>
      </div>
      <div className="filter-group">
        <label>Host group</label>
        <select value={filters.groupids || ""} onChange={e => onChange({ groupids: e.target.value })}>
          <option value="">All groups</option>
          {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
        </select>
      </div>
      <div className="filter-group">
        <label>Range</label>
        <select value={filters.range || "24h"} onChange={e => onChange({ range: e.target.value })}>
          {RANGE_OPTIONS_E.map(o => <option key={o.key} value={o.key}>{o.label}</option>)}
        </select>
      </div>
      <div className="filter-group grow">
        <label>Search</label>
        <input
          type="text"
          placeholder="Event name or host…"
          defaultValue={filters.search || ""}
          onKeyDown={e => { if (e.key === "Enter") onChange({ search: e.target.value }); }}
          onBlur={e => onChange({ search: e.target.value })}
        />
      </div>
      <div className="filter-group">
        <button className="btn-secondary" onClick={() => onChange({
          severity: "", value: "any", groupids: "", search: "", range: "24h"
        })}>Reset</button>
      </div>
    </div>
  );
};

const MetricStripE = ({ metrics }) => {
  const cells = [
    { label: "Events", value: metrics.total, color: "var(--fg)" },
    { label: "Firing", value: metrics.fired, color: "var(--err)" },
    { label: "Resolved", value: metrics.resolved, color: "var(--ok)" },
    { label: "Disaster", value: metrics.bySeverity.disaster, color: "var(--err)" },
    { label: "High", value: metrics.bySeverity.high, color: "var(--err)" },
    { label: "Warning", value: metrics.bySeverity.warning, color: "var(--warn)" },
    { label: "Mean TTR", value: metrics.mttrStr, color: "var(--info)" }
  ];
  return (
    <div className="card sev-strip">
      {cells.map(c => (
        <div className="sev-cell" key={c.label}>
          <div className="sev-cell-h"><div className="sev-cell-lbl">{c.label}</div></div>
          <div className="sev-cell-v" style={{ color: c.color }}>{c.value}</div>
        </div>
      ))}
    </div>
  );
};

const TimelineCard = ({ timeline, range }) => {
  const max = Math.max(1, ...timeline);
  return (
    <div className="card">
      <div className="card-h">
        <h3>Events over time</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">{range || "24h"} · firing</span>
      </div>
      <div className="card-b">
        <div className="trend-bars" style={{ height: 88, padding: "10px 8px" }}>
          {timeline.map((n, i) => (
            <div className="trend-bar" key={i}>
              <div className="trend-bar-fill" style={{
                height: `${(n / max) * 100}%`,
                background: n > 0 ? "var(--err)" : "var(--line)"
              }} />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

const TopHostsCard = ({ items }) => (
  <div className="card">
    <div className="card-h">
      <h3>Top hosts</h3>
      <SourceBadge src="zbx" />
    </div>
    <div className="card-b tight">
      {items.length === 0 ? <div className="empty">No events in window.</div> : (
        <table className="link-tbl">
          <tbody>
            {items.map(it => (
              <tr key={it.name}>
                <td style={{ color: "var(--fg)" }}>{it.name}</td>
                <td style={{ textAlign: "right", fontFamily: "var(--mono)", color: "var(--fg-2)" }}>{it.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  </div>
);

const EventsTable = ({ rows }) => {
  if (!rows.length) {
    return (
      <div className="card">
        <div className="card-h"><h3>Events</h3></div>
        <div className="empty" style={{ padding: 60 }}>No events match the current filters.</div>
      </div>
    );
  }
  return (
    <div className="card">
      <div className="card-h">
        <h3>Events</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">{rows.length} row{rows.length === 1 ? "" : "s"}</span>
      </div>
      <div className="card-b tight" style={{ maxHeight: 720, overflowY: "auto" }}>
        <table className="link-tbl">
          <thead>
            <tr>
              <th style={{ width: 160 }}>Time</th>
              <th style={{ width: 90 }}>Severity</th>
              <th style={{ width: 80 }}>Type</th>
              <th style={{ width: 220 }}>Host</th>
              <th>Event</th>
              <th style={{ width: 220 }}>Host groups</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.eventid}>
                <td style={{ fontFamily: "var(--mono)", fontSize: 11.5, color: "var(--fg-2)" }}>{r.ts}</td>
                <td>
                  <span className={"sev-badge sev-" + r.severity}>{r.severity}</span>
                </td>
                <td style={{ fontSize: 11, color: r.value === "firing" ? "var(--err)" : "var(--ok)" }}>
                  {r.value}
                </td>
                <td style={{ color: "var(--fg)", fontWeight: 500 }}>{r.host}</td>
                <td>{r.name}</td>
                <td style={{ fontSize: 10.5, color: "var(--muted)" }}>{r.hostgroups.join(", ")}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

const EventsApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_E);
  const [data] = useEventsData();
  const [refreshing, setRefreshing] = React.useState(false);
  const [now, setNow] = React.useState(new Date().toLocaleTimeString());

  React.useEffect(() => {
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.showSourceBadges]);

  React.useEffect(() => {
    const onData = () => { setNow(new Date().toLocaleTimeString()); setRefreshing(false); };
    window.addEventListener("tcs:events-data", onData);
    return () => window.removeEventListener("tcs:events-data", onData);
  }, []);

  const onFilter = React.useCallback((delta) => {
    setRefreshing(true);
    window.tcsEventsFetch(delta);
  }, []);

  const doRefresh = React.useCallback(() => {
    setRefreshing(true);
    window.tcsEventsRefresh();
  }, []);

  return (
    <div className="app" data-density={t.density} data-screen-label="Events">
      <GlobalSidebar active="events" />
      <div className="main">
        <GlobalTopbar crumb={["Operations", "Events"]} onRefresh={doRefresh} refreshing={refreshing} />

        <div className="page-header" style={{ alignItems: "center" }}>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>Events Console</h1>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>OPERATIONS</span>
            </div>
            <div className="host-meta">
              <span className="pill"><span className="lbl">Last refresh</span> <span className="v">{now}</span></span>
              <span className="pill"><span className="lbl">Auto-refresh</span> <span className="v">30s</span></span>
              <span className="pill"><span className="lbl">In window</span> <span className="v">{data.metrics.total}</span></span>
              <span className="pill"><span className="lbl">MTTR</span> <span className="v">{data.metrics.mttrStr}</span></span>
            </div>
          </div>
        </div>

        <div className="body">
          <FilterBarE filters={data.filters} groups={data.groups} onChange={onFilter} />
          <MetricStripE metrics={data.metrics} />
          <div className="row" style={{ gridTemplateColumns: "1.5fr 1fr", marginTop: 14 }}>
            <TimelineCard timeline={data.metrics.timeline} range={data.filters.range} />
            <TopHostsCard items={data.metrics.topHosts} />
          </div>
          <div style={{ marginTop: 14 }}>
            <EventsTable rows={data.events} />
          </div>
        </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Layout">
          <TweakRadio label="Density" value={t.density} options={[
            { value: "spacious", label: "Spacious" },
            { value: "balanced", label: "Balanced" },
            { value: "dense",    label: "Dense" }
          ]} onChange={v => setTweak("density", v)} />
          <TweakToggle label="Show data-source badges" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<EventsApp />);
