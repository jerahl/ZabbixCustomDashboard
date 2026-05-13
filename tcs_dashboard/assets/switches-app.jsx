// Switches dashboard app entry

const { useState: useStateSWA, useEffect: useEffectSWA } = React;

const TWEAK_DEFAULTS_SW = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#d92929",
  "showSourceBadges": true,
  "selectedSwitch": "ARC-MDF",
  "portStyle": "filled"
}/*EDITMODE-END*/;

const SwitchesApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_SW);
  // When the bridge bound a live switch, prefer its shortname over the
  // sticky tweak so the navigator highlights the correct row on load.
  const liveHost = (window.SWITCH_BOOT && window.SWITCH_BOOT.host) || null;
  const initialId = liveHost ? (liveHost.host || liveHost.visible_name || t.selectedSwitch) : t.selectedSwitch;
  const [activeId, setActiveId] = useStateSWA(initialId);
  const [selectedPort, setSelectedPort] = useStateSWA(() => {
    const m1 = window.ARC_MDF_STACK[0];
    const p = m1.ports.find(pp => pp.n === 18);
    return { member: 1, port: 18, detail: window.makePortDetail(1, p) };
  });
  const onSelectPort = (memberIdx, port) => {
    if (port.state === "absent") return;
    const detail = window.makePortDetail(memberIdx, port);
    setSelectedPort({ member: memberIdx, port: port.n, detail });
  };

  useEffectSWA(() => {
    document.documentElement.style.setProperty("--zbx", t.accent);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.showSourceBadges]);

  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;

  // Find selected host across all sites
  const allHosts = window.SWITCH_SITES.flatMap(s => s.switches);
  const host = allHosts.find(h => h.id === activeId) || allHosts.find(h => h.id === "ARC-MDF") || allHosts[0];

  return (
    <div className="app" data-density={t.density} style={{ fontSize: `${13 * densityVar}px` }}>
      <NVRSidebar active="switches" />
      <div className="main">
        <NVRTopbar crumb={["Network", "Extreme Switches", host.id]} />
        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>Switch Fleet</h1>
              <span className="ip">{host.id}</span>
              <span className="role-tag av" style={{ fontSize: 10, padding: "1px 8px" }}>Extreme · {host.model}</span>
            </div>
            <div className="host-meta">
              <span className="pill"><span className="dot" style={{ background: "var(--ok)" }} /> All members up</span>
              <span className="pill"><span className="lbl">Stack</span> <span className="v">{host.members} member{host.members > 1 ? "s" : ""}</span></span>
              <span className="pill"><span className="lbl">Ports</span> <span className="v">{host.up} up · {host.down} down · {host.ports} total</span></span>
              <span className="pill"><span className="lbl">PoE</span> <span className="v">{host.poe} drawing</span></span>
              <span className="pill"><span className="lbl">EXOS</span> <span className="v">31.7.1.4</span></span>
            </div>
          </div>
          <div className="timerange">
            <Icon name="calendar" />
            <span className="range-val">May 8 09:42 — May 9 09:42</span>
            <Icon name="chevron" />
          </div>
        </div>

        <div className="tabs">
          <div className="tab active">Port Status</div>
          <div className="tab">Topology</div>
          <div className="tab">Stack Health</div>
          <div className="tab">VLAN / EAPS</div>
          <div className="tab">PoE Budget</div>
          <div className="tab">Macros · CLI</div>
          <div className="tab">Triggers <span className="badge warn">3</span></div>
          <div className="tab">Config Backups</div>
        </div>

        <div className="body" data-screen-label="Switches Dashboard">
          <StackKPIs host={host} />
          <div className="switch-layout">
            <HostNavigator activeId={activeId} onSelect={(id) => { setActiveId(id); setTweak("selectedSwitch", id); }} />
            <div style={{ display: "flex", flexDirection: "column", gap: 14, minWidth: 0 }}>
              <SwitchPortWidget host={host} selected={selectedPort} onSelectPort={onSelectPort} />
              <PortDetailRow host={host} detail={selectedPort ? selectedPort.detail : null} />
              <UplinkTable />
            </div>
          </div>
          <div className="switch-problems-row">
            <ProblemsWidget />
          </div>
        </div>
      </div>

      <TweaksPanel title="Tweaks">
        <TweakSection title="Layout">
          <TweakRadio label="Density" value={t.density} options={[{value:"spacious",label:"Spacious"},{value:"balanced",label:"Balanced"},{value:"dense",label:"Dense"}]} onChange={v => setTweak("density", v)} />
        </TweakSection>
        <TweakSection title="Visual">
          <TweakColor label="Primary accent" value={t.accent} options={["#d92929","#5b8cff","#34d399","#7c5cff","#f5b300"]} onChange={v => setTweak("accent", v)} />
          <TweakToggle label="Show data-source badges" value={t.showSourceBadges} onChange={v => setTweak("showSourceBadges", v)} />
        </TweakSection>
        <TweakSection title="Switch view">
          <TweakSelect label="Active host" value={activeId} options={allHosts.map(h => ({value: h.id, label: h.id}))} onChange={v => { setActiveId(v); setTweak("selectedSwitch", v); }} />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<SwitchesApp />);
