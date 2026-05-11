// Unified sidebar + topbar used by every page in the project.
// Pass `active` to highlight the current page. Values:
//   "global"        — Global Dashboard
//   "wireless"      — Wireless APs (AP Detail / Zabbix Dashboard)
//   "switches"      — Switches Dashboard
//   "zbx-servers"   — Servers Dashboard
//   "problems"      — (future) problems list
//   "nvr-overview"  — Surveillance Dashboard
//   "nvr-cameras"   — Camera Detail
//   "nvr-servers"   — Recording Server Detail

// Centralised URLs of every page in this module + the default Zabbix UI.
// All sidebars read from this object, so renaming an action only requires a
// change here.
window.TCS_NAV = window.TCS_NAV || {
  zabbixDefault: "zabbix.php?action=dashboard.view",
  global:        "zabbix.php?action=tcs.global.view",
  apDetail:      "zabbix.php?action=tcs.dashboard.view",
  switches:      "zabbix.php?action=tcs.switches.view",
  servers:       "zabbix.php?action=tcs.servers.view",
  surveillance:  "zabbix.php?action=tcs.surveillance.view",
  cameraDetail:  "zabbix.php?action=tcs.camera.view",
  serverDetail:  "zabbix.php?action=tcs.server.view"
};

const GlobalSidebar = ({ active }) => {
  const NAV = window.TCS_NAV;
  const item = (key, href, icon, label, count, countClass) => (
    <a
      className={"nav-item" + (active === key ? " active" : "")}
      href={href}
    >
      <Icon name={icon} /> {label}
      {count !== undefined && (
        <span className={"nav-count" + (countClass ? " " + countClass : "")}>{count}</span>
      )}
    </a>
  );

  return (
    <aside className="sidebar">
      <a className="back-to-zabbix" href={NAV.zabbixDefault} title="Back to default Zabbix UI">
        <Icon name="back" /> <span>Default Zabbix Dashboard</span>
      </a>

      <div className="brand">
        <div className="brand-mark">Z·P</div>
        <div>
          <div className="brand-name">Zabbix · TCS</div>
          <div className="brand-sub">Network operations</div>
        </div>
      </div>

      <div className="nav-section">
        <div className="nav-label">Monitoring</div>
        {item("global",      NAV.global,        "map",      "Global Dashboard")}
        {item("hosts",       NAV.global,        "ap",       "Hosts",        "2,418")}
        {item("wireless",    NAV.apDetail,      "wifi",     "Wireless APs", "1,184")}
        {item("switches",    NAV.switches,      "ethernet", "Switches",     "312")}
        {item("zbx-servers", NAV.servers,       "ap",       "Servers",      "17")}
        {item("problems",    NAV.global,        "alert",    "Problems",     "47", "warn")}
        {item("events",      NAV.global,        "events",   "Events")}
      </div>

      <div className="nav-section">
        <div className="nav-label">Identity (PacketFence)</div>
        {item("clients",  NAV.global, "clients", "Connected Devices", "12,847")}
        {item("nac",      NAV.global, "shield",  "NAC Policies")}
        {item("sessions", NAV.global, "user",    "User Sessions")}
        {item("quar",     NAV.global, "lock",    "Quarantine", "2", "warn")}
      </div>

      <div className="nav-section">
        <div className="nav-label">Surveillance (Milestone)</div>
        {item("nvr-overview", NAV.surveillance,  "map",      "NOC Overview")}
        {item("nvr-cameras",  NAV.cameraDetail,  "ap",       "Cameras",            "1,147")}
        {item("nvr-servers",  NAV.serverDetail,  "ethernet", "Recording Servers",  "8")}
        {item("nvr-evid",     NAV.surveillance,  "shield",   "Evidence Lock",      "7")}
        {item("nvr-alarms",   NAV.surveillance,  "alert",    "VMS Alarms",         "12", "warn")}
      </div>

      <div className="nav-section">
        <div className="nav-label">Sites</div>
        <a className="nav-item">Bryant High School</a>
        <a className="nav-item muted">Central High School</a>
        <a className="nav-item muted">Northridge High School</a>
        <a className="nav-item muted">+ 23 more sites</a>
      </div>

      <div className="sidebar-footer">
        <div className="row"><span>Zabbix Server</span><span className="ok">● 7.0.4</span></div>
        <div className="row"><span>PacketFence API</span><span className="ok">● v12.3</span></div>
        <div className="row"><span>XProtect Mgmt</span><span className="ok">● 24.2 R2</span></div>
      </div>
    </aside>
  );
};

const GlobalTopbar = ({ crumb, search = "Find host, MAC, user, IP…" }) => (
  <div className="topbar">
    <div className="icon-btn"><Icon name="back" /></div>
    <div className="crumb">
      {(crumb || ["Global", "Overview"]).map((c, i, arr) => (
        <React.Fragment key={i}>
          {i > 0 && <span className="sep">/</span>}
          <span className={i === arr.length - 1 ? "seg" : ""}>{c}</span>
        </React.Fragment>
      ))}
    </div>
    <div className="spacer" />
    <div className="search">
      <Icon name="search" />
      <input placeholder={search} readOnly />
      <kbd>⌘K</kbd>
    </div>
    <div className="icon-btn" title="Refresh"><Icon name="refresh" /></div>
    <div className="icon-btn" title="More"><Icon name="more" /></div>
  </div>
);

window.GlobalSidebar = GlobalSidebar;
window.GlobalTopbar = GlobalTopbar;
// Back-compat shims so older code that referenced NVRSidebar / NVRTopbar
// still resolves. New pages should use GlobalSidebar / GlobalTopbar directly.
window.NVRSidebar = GlobalSidebar;
window.NVRTopbar = GlobalTopbar;
