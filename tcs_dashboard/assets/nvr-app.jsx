// Surveillance overview app entry

const { useState: useStateOV, useEffect: useEffectOV } = React;

// Safety defaults — if surveillance-bridge.jsx hasn't finished publishing
// yet, or if a refresh swapped MILESTONE for a partial object, fall back
// here rather than throwing on .toFixed / .toLocaleString.
const MS_DEFAULTS = {
  product: "—", version: "—", managementServer: "—",
  licenseDeviceTotal: 0, licenseDeviceUsed: 0, licenseHwTotal: 0,
  recordingServers: 0, recordingServersOnline: 0,
  failoverServers: 0, mobileServers: 0,
  smartClientSessions: 0, webClientSessions: 0,
  activeAlarms: 0, alarmsAck: 0,
  retentionDays: 0, storageTotalTB: 0, storageUsedTB: 0,
  evidenceLockSlots: 0, evidenceLockUsed: 0
};
const _ms = () => Object.assign({}, MS_DEFAULTS, window.MILESTONE || {});
const _nz = (v, d = 0) => (typeof v === "number" && !Number.isNaN(v) ? v : d);

const TWEAK_DEFAULTS_OV = /*EDITMODE-BEGIN*/{
  "density": "balanced",
  "accent": "#d92929",
  "showSourceBadges": true,
  "wallSite": "",
  "activeTab": "overview"
}/*EDITMODE-END*/;

const NVRApp = () => {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS_OV);
  const [activeTab, setActiveTab] = useStateOV(t.activeTab || "overview");
  useEffectOV(() => {
    document.documentElement.style.setProperty("--zbx", t.accent);
    document.documentElement.classList.toggle("hide-src-badges", !t.showSourceBadges);
  }, [t.accent, t.showSourceBadges]);
  // Publish the wall-site tweak onto window so FleetWidgets (rendered
  // without prop drilling) can read it.
  useEffectOV(() => { window.TCS_WALL_SITE = t.wallSite || ""; }, [t.wallSite]);

  // Snapshot the live globals once per render so we never re-deref something
  // mid-tree that the bridge swapped out underneath us.
  const M = _ms();
  const SITES_RAW = Array.isArray(window.SITES) ? window.SITES : [];
  const CAMS_RAW  = Array.isArray(window.CAMERAS) ? window.CAMERAS : [];
  const SRVS_RAW  = Array.isArray(window.SERVERS) ? window.SERVERS : [];

  const densityVar = t.density === "spacious" ? 1.15 : t.density === "dense" ? 0.85 : 1;

  return (
    <div className="app" data-density={t.density} style={{ fontSize: `${13 * densityVar}px` }}>
      <NVRSidebar active="nvr-overview" />
      <div className="main">
        <NVRTopbar crumb={["Surveillance", "Milestone XProtect", "NOC Overview"]} />
        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>Surveillance NOC</h1>
              <span className="ip">{M.managementServer}</span>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>{M.product}</span>
            </div>
            <div className="host-meta">
              {(() => {
                const rsOnline = _nz(M.recordingServersOnline);
                const rsTotal  = _nz(M.recordingServers);
                const allUp    = rsTotal > 0 && rsOnline === rsTotal;
                const color    = allUp ? "var(--ok)" : (rsOnline > 0 ? "var(--warn)" : "var(--err)");
                const label    = rsTotal === 0
                  ? "No recording servers discovered"
                  : (allUp ? "All recording servers online" : `${rsOnline} / ${rsTotal} recording servers online`);
                return <span className="pill"><span className="dot" style={{ background: color }} /> {label}</span>;
              })()}
              <span className="pill"><span className="lbl">XProtect ver</span> <span className="v">{M.version}</span></span>
              <span className="pill"><span className="lbl">Cameras</span> <span className="v">{_nz(M.licenseDeviceUsed).toLocaleString()} / {_nz(M.licenseDeviceTotal).toLocaleString()} licensed</span></span>
              <span className="pill"><span className="lbl">Storage</span> <span className="v">{_nz(M.storageUsedTB).toFixed(1)} / {_nz(M.storageTotalTB)} TB</span></span>
              <span className="pill"><span className="lbl">Sites</span> <span>{SITES_RAW.length}</span></span>
            </div>
          </div>
          <div className="timerange">
            <Icon name="calendar" />
            <span className="range-val">Last 24h · live</span>
            <Icon name="chevron" />
          </div>
        </div>

        <div className="tabs">
          {(window.NVR_TABS || []).map(tab => (
            <div
              key={tab.id}
              className={"tab" + (activeTab === tab.id ? " active" : "")}
              onClick={() => { setActiveTab(tab.id); setTweak("activeTab", tab.id); }}
            >
              {tab.label}
              {tab.badge && <span className={"badge" + (tab.badge.kind ? " " + tab.badge.kind : "")}>{tab.badge.v}</span>}
            </div>
          ))}
        </div>

        <div className="body" data-screen-label={`Surveillance · ${activeTab}`}>
          {activeTab === "overview" && <FleetWidgets />}
          {activeTab === "sites"    && window.NvrTabSites    && <NvrTabSites />}
          {activeTab === "cameras"  && window.NvrTabCameras  && <NvrTabCameras />}
          {activeTab === "servers"  && window.NvrTabServers  && <NvrTabServers />}
          {activeTab === "alarms"   && window.NvrTabAlarms   && <NvrTabAlarms />}
          {activeTab === "storage"  && window.NvrTabStorage  && <NvrTabStorage />}
          {activeTab === "evidence" && window.NvrTabEvidence && <NvrTabEvidence />}
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
        <TweakSection title="Camera wall">
          <TweakSelect label="Site to show" value={t.wallSite} options={SITES_RAW.map(s => ({value: s.name, label: s.name}))} onChange={v => setTweak("wallSite", v)} />
        </TweakSection>
      </TweaksPanel>
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("root")).render(<NVRApp />);
