// Problems console — full-page list of current open problems with
// advanced filtering and rolled-up metrics.

const SEVERITIES = [
  { key: "disaster", label: "Disaster", color: "var(--err)"  },
  { key: "high",     label: "High",     color: "var(--err)"  },
  { key: "warning",  label: "Warning",  color: "var(--warn)" },
  { key: "info",     label: "Info",     color: "var(--info)" }
];

const AGE_OPTIONS = [
  { key: "all", label: "Any age" },
  { key: "1h",  label: "≤ 1h"    },
  { key: "6h",  label: "≤ 6h"    },
  { key: "24h", label: "≤ 24h"   },
  { key: "7d",  label: "≤ 7d"    }
];

const ACK_OPTIONS = [
  { key: "any",   label: "Any"          },
  { key: "false", label: "Unacked only" },
  { key: "true",  label: "Acked only"   }
];

const TWEAK_DEFAULTS_P = /*EDITMODE-BEGIN*/{
  density: "balanced",
  showSourceBadges: true
}/*EDITMODE-END*/;

const useProblemsData = () => {
  const [tick, setTick] = React.useState(0);
  React.useEffect(() => {
    const onData = () => setTick(n => n + 1);
    window.addEventListener("tcs:problems-data", onData);
    return () => window.removeEventListener("tcs:problems-data", onData);
  }, []);
  return [window.PROBLEMS_DATA, tick];
};

const FilterBar = ({ filters, groups, onChange }) => {
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
          {SEVERITIES.map(s => (
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
        <label>Acknowledged</label>
        <select value={filters.ack || "any"} onChange={e => onChange({ ack: e.target.value })}>
          {ACK_OPTIONS.map(o => <option key={o.key} value={o.key}>{o.label}</option>)}
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
        <label>Age</label>
        <select value={filters.maxAge || "all"} onChange={e => onChange({ maxAge: e.target.value })}>
          {AGE_OPTIONS.map(o => <option key={o.key} value={o.key}>{o.label}</option>)}
        </select>
      </div>
      <div className="filter-group grow">
        <label>Search</label>
        <input
          type="text"
          placeholder="Trigger or host name…"
          defaultValue={filters.search || ""}
          onKeyDown={e => { if (e.key === "Enter") onChange({ search: e.target.value }); }}
          onBlur={e => onChange({ search: e.target.value })}
        />
      </div>
      <div className="filter-group">
        <button className="btn-secondary" onClick={() => onChange({
          severity: "", ack: "any", groupids: "", search: "", maxAge: "all"
        })}>Reset</button>
      </div>
    </div>
  );
};

const MetricStrip = ({ metrics }) => {
  const cells = [
    { label: "Open problems", value: metrics.total,               color: "var(--fg)"   },
    { label: "Disaster",      value: metrics.bySeverity.disaster, color: "var(--err)"  },
    { label: "High",          value: metrics.bySeverity.high,     color: "var(--err)"  },
    { label: "Warning",       value: metrics.bySeverity.warning,  color: "var(--warn)" },
    { label: "Info",          value: metrics.bySeverity.info,     color: "var(--info)" },
    { label: "Unacked",       value: metrics.unacked,             color: "var(--warn)" }
  ];
  return (
    <div className="card metric-strip">
      {cells.map(c => (
        <div className="sev-cell" key={c.label}>
          <div className="sev-cell-h"><div className="sev-cell-lbl">{c.label}</div></div>
          <div className="sev-cell-v" style={{ color: c.color }}>{c.value}</div>
        </div>
      ))}
    </div>
  );
};

const TopList = ({ title, items, emptyLabel = "No data" }) => (
  <div className="card">
    <div className="card-h">
      <h3>{title}</h3>
      <SourceBadge src="zbx" />
    </div>
    <div className="card-b tight">
      {items.length === 0 ? (
        <div className="empty">{emptyLabel}</div>
      ) : (
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

const ProblemsTable = ({ rows }) => {
  if (!rows.length) {
    return (
      <div className="card">
        <div className="card-h"><h3>Problems</h3></div>
        <div className="empty" style={{ padding: 60 }}>No problems match the current filters.</div>
      </div>
    );
  }
  return (
    <div className="card">
      <div className="card-h">
        <h3>Problems</h3>
        <SourceBadge src="zbx" />
        <div className="h-spacer" />
        <span className="h-meta">{rows.length} row{rows.length === 1 ? "" : "s"}</span>
      </div>
      <div className="card-b tight" style={{ maxHeight: 720, overflowY: "auto" }}>
        <table className="link-tbl">
          <thead>
            <tr>
              <th style={{ width: 90 }}>Severity</th>
              <th style={{ width: 70 }}>Ack</th>
              <th style={{ width: 110 }}>Age</th>
              <th style={{ width: 200 }}>Host</th>
              <th>Trigger</th>
              <th style={{ width: 220 }}>Host groups</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.eventid}>
                <td>
                  <span className={"sev-badge sev-" + r.severity}>{r.severity}</span>
                </td>
                <td style={{ color: r.ack ? "var(--ok)" : "var(--warn)", fontSize: 11 }}>
                  {r.ack ? "ack" : "unack"}
                </td>
                <td style={{ fontFamily: "var(--mono)", color: "var(--fg-2)" }}>{r.ageStr}</td>
                <td style={{ color: "var(--fg)", fontWeight: 500 }}>{r.host}</td>
                <td>{r.trigger}</td>
                <td style={{ fontSize: 10.5, color: "var(--muted)" }}>{r.hostgroups.join(", ")}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

const ProblemsApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_P);
  const [data] = useProblemsData();
  const [refreshing, setRefreshing] = React.useState(false);
  const [now, setNow] = React.useState(new Date().toLocaleTimeString());

  React.useEffect(() => {
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.showSourceBadges]);

  React.useEffect(() => {
    const onData = () => { setNow(new Date().toLocaleTimeString()); setRefreshing(false); };
    window.addEventListener("tcs:problems-data", onData);
    return () => window.removeEventListener("tcs:problems-data", onData);
  }, []);

  const onFilter = React.useCallback((delta) => {
    setRefreshing(true);
    window.tcsProblemsFetch(delta);
  }, []);

  const doRefresh = React.useCallback(() => {
    setRefreshing(true);
    window.tcsProblemsRefresh();
  }, []);

  return (
    <div className="app" data-density={t.density} data-screen-label="Problems">
      <GlobalSidebar active="problems" />
      <div className="main">
        <GlobalTopbar crumb={["Operations", "Problems"]} onRefresh={doRefresh} refreshing={refreshing} />

        <div className="page-header" style={{ alignItems: "center" }}>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>Problems</h1>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>OPERATIONS</span>
            </div>
            <div className="host-meta">
              <span className="pill"><span className="lbl">Last refresh</span> <span className="v">{now}</span></span>
              <span className="pill"><span className="lbl">Auto-refresh</span> <span className="v">30s</span></span>
              <span className="pill"><span className="lbl">Matches</span> <span className="v">{data.metrics.total}</span></span>
              <span className="pill"><span className="lbl">Mean age</span> <span className="v">{data.metrics.avgAgeStr}</span></span>
            </div>
          </div>
        </div>

        <div className="body">
          <FilterBar filters={data.filters} groups={data.groups} onChange={onFilter} />
          <MetricStrip metrics={data.metrics} />
          <div className="row" style={{ gridTemplateColumns: "1fr 1fr", marginTop: 14 }}>
            <TopList title="Top hosts" items={data.metrics.topHosts} />
            <TopList title="Top host groups" items={data.metrics.topGroups} />
          </div>
          <div style={{ marginTop: 14 }}>
            <ProblemsTable rows={data.problems} />
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

ReactDOM.createRoot(document.getElementById("root")).render(<ProblemsApp />);
