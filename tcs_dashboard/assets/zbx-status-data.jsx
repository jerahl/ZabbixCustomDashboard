// Zabbix Server + Proxy Status — synthetic data
// Aligned with the existing AP_SITES fleet so proxy → site mappings feel real.

const ZBX_SUMMARY = {
  version: "7.0.4",
  build:   "rel-2026-03-12",
  upSince: "2026-04-03 02:14:08",
  upHuman: "51d 7h 26m",
  haMode:  "active",      // cluster HA enabled
  primary: "zbx-srv-01",
  standby: "zbx-srv-02",
  reqPerf: 8420,          // required NVPS (sum of templates)
  actPerf: 7918,          // actual NVPS observed
  hosts:   { enabled: 1842, disabled: 17, templates: 312, monitored: 1842 },
  items:   { enabled: 184726, disabled: 1204, notSupported: 318 },
  triggers:{ enabled: 42118, problem: 47, suppressed: 9, ok: 41062 },
  queue:   { total: 142, ten_min: 4, half_hr: 0, hour: 0, day: 0 },
};

// HA cluster server nodes
const ZBX_NODES = [
  {
    id: "zbx-srv-01", host: "zbx-srv-01.tcs.local", ip: "10.0.10.21",
    role: "active",       // active | standby | unavailable
    uptime: "51d 07h",
    cpu: 38, mem: 64, disk: 41, dbConn: 84,
    nvps: 7918,
    lastSeen: "now",
    version: "7.0.4",
    services: [
      { n: "zabbix-server", s: "ok" },
      { n: "nginx",         s: "ok" },
      { n: "php-fpm",       s: "ok" },
      { n: "mariadb",       s: "ok" },
      { n: "ha-manager",    s: "ok" },
      { n: "snmptrapd",     s: "warn" },
    ],
  },
  {
    id: "zbx-srv-02", host: "zbx-srv-02.tcs.local", ip: "10.0.10.22",
    role: "standby",
    uptime: "51d 07h",
    cpu: 4, mem: 22, disk: 41, dbConn: 12,
    nvps: 0,
    lastSeen: "5s ago",
    version: "7.0.4",
    services: [
      { n: "zabbix-server", s: "standby" },
      { n: "nginx",         s: "ok" },
      { n: "php-fpm",       s: "ok" },
      { n: "mariadb",       s: "ok" },
      { n: "ha-manager",    s: "ok" },
      { n: "snmptrapd",     s: "ok" },
    ],
  },
];

// Internal Zabbix server processes — each with %busy
// Values mostly match a moderately busy 1800-host installation
const ZBX_PROCESSES = [
  { group: "Pollers",    n: "poller",                 forks: 50, busy: 38 },
  { group: "Pollers",    n: "unreachable poller",     forks: 5,  busy: 12 },
  { group: "Pollers",    n: "icmp pinger",            forks: 6,  busy: 22 },
  { group: "Pollers",    n: "history poller",         forks: 5,  busy: 8  },
  { group: "Pollers",    n: "snmp trapper",           forks: 1,  busy: 47 },
  { group: "Pollers",    n: "trapper",                forks: 10, busy: 18 },
  { group: "Pollers",    n: "proxy poller",           forks: 2,  busy: 31 },
  { group: "Pollers",    n: "java poller",            forks: 5,  busy: 4  },
  { group: "Data flow",  n: "history syncer",         forks: 8,  busy: 62 },
  { group: "Data flow",  n: "preprocessing worker",   forks: 12, busy: 71 },
  { group: "Data flow",  n: "preprocessing manager",  forks: 1,  busy: 18 },
  { group: "Data flow",  n: "lld worker",             forks: 4,  busy: 14 },
  { group: "Data flow",  n: "lld manager",            forks: 1,  busy: 6  },
  { group: "Data flow",  n: "trigger housekeeper",    forks: 1,  busy: 84, alert: true },
  { group: "Data flow",  n: "history poller",         forks: 5,  busy: 8  },
  { group: "Triggers",   n: "escalator",              forks: 3,  busy: 11 },
  { group: "Triggers",   n: "alerter",                forks: 3,  busy: 5  },
  { group: "Triggers",   n: "alert syncer",           forks: 1,  busy: 2  },
  { group: "Triggers",   n: "alert manager",          forks: 1,  busy: 3  },
  { group: "Triggers",   n: "task manager",           forks: 1,  busy: 1  },
  { group: "Triggers",   n: "service manager",        forks: 1,  busy: 7  },
  { group: "Discovery",  n: "discoverer",             forks: 5,  busy: 24 },
  { group: "Discovery",  n: "auto-registration",      forks: 1,  busy: 3  },
  { group: "Discovery",  n: "vmware collector",       forks: 2,  busy: 0  },
  { group: "Discovery",  n: "ipmi poller",            forks: 1,  busy: 0  },
  { group: "Discovery",  n: "ipmi manager",           forks: 1,  busy: 0  },
  { group: "Housekeeping", n: "housekeeper",          forks: 1,  busy: 93, alert: true },
  { group: "Housekeeping", n: "configuration syncer", forks: 1,  busy: 4  },
  { group: "Housekeeping", n: "db config worker",     forks: 1,  busy: 2  },
  { group: "Housekeeping", n: "report manager",       forks: 1,  busy: 0  },
  { group: "Housekeeping", n: "report writer",        forks: 3,  busy: 0  },
];

// Cache usage (free % — what Zabbix exposes)
const ZBX_CACHES = [
  { n: "Configuration cache",  used: 38, size: "16 MB",   note: "CacheSize" },
  { n: "History cache",        used: 12, size: "32 MB",   note: "HistoryCacheSize" },
  { n: "History index cache",  used:  8, size: "16 MB",   note: "HistoryIndexCacheSize" },
  { n: "Trend cache",          used: 18, size: "16 MB",   note: "TrendCacheSize" },
  { n: "Value cache",          used: 71, size: "128 MB",  note: "ValueCacheSize", warn: true },
  { n: "VMware cache",         used:  2, size: "8 MB",    note: "VMwareCacheSize" },
];

// Proxies — 8 proxies across TCS sites, mostly active mode
const ZBX_PROXIES = [
  {
    id: "zbx-proxy-tcs-01", host: "Bryant HS", site: "Bryant High School",
    ip: "172.16.97.5", mode: "active", version: "7.0.4", encrypted: "PSK",
    status: "ok", lastSeen: "3s",
    hosts: 412, items: 41280, nvps: 1842, queue: 8,
    cpu: 22, mem: 48, db: "SQLite 3.45",
    notes: null,
  },
  {
    id: "zbx-proxy-tcs-02", host: "Central HS", site: "Central High School",
    ip: "172.17.4.5", mode: "active", version: "7.0.4", encrypted: "PSK",
    status: "ok", lastSeen: "6s",
    hosts: 318, items: 33104, nvps: 1518, queue: 2,
    cpu: 14, mem: 36, db: "SQLite 3.45",
    notes: null,
  },
  {
    id: "zbx-proxy-tcs-03", host: "Northridge HS", site: "Northridge High School",
    ip: "172.18.2.5", mode: "active", version: "7.0.4", encrypted: "PSK",
    status: "ok", lastSeen: "11s",
    hosts: 289, items: 28410, nvps: 1284, queue: 0,
    cpu: 19, mem: 42, db: "SQLite 3.45",
    notes: null,
  },
  {
    id: "zbx-proxy-tcs-04", host: "Tusc. Career & Tech", site: "Tuscaloosa Career & Tech Academy",
    ip: "10.60.2.5", mode: "active", version: "7.0.4", encrypted: "PSK",
    status: "warn", lastSeen: "2m 14s",
    hosts: 168, items: 14812, nvps: 642, queue: 47,
    cpu: 64, mem: 71, db: "SQLite 3.45",
    notes: "ConfigFrequency drift · queue rising 14m",
  },
  {
    id: "zbx-proxy-tcs-05", host: "Westlawn MS", site: "Westlawn Middle",
    ip: "10.70.2.5", mode: "passive", version: "7.0.4", encrypted: "Cert",
    status: "ok", lastSeen: "1s",
    hosts: 184, items: 18204, nvps: 802, queue: 4,
    cpu: 12, mem: 28, db: "SQLite 3.45",
    notes: null,
  },
  {
    id: "zbx-proxy-tcs-06", host: "Eastwood MS", site: "Eastwood Middle",
    ip: "10.80.2.5", mode: "active", version: "7.0.4", encrypted: "PSK",
    status: "ok", lastSeen: "4s",
    hosts: 142, items: 13812, nvps: 612, queue: 0,
    cpu: 11, mem: 24, db: "SQLite 3.45",
    notes: null,
  },
  {
    id: "zbx-proxy-tcs-07", host: "Arcadia ES", site: "Arcadia Elementary",
    ip: "10.24.2.5", mode: "active", version: "6.4.18", encrypted: "PSK",
    status: "warn", lastSeen: "8s",
    hosts: 96, items: 8204, nvps: 318, queue: 0,
    cpu: 8, mem: 19, db: "SQLite 3.41",
    notes: "Version mismatch · upgrade pending",
  },
  {
    id: "zbx-proxy-tcs-08", host: "Central Office", site: "TCS Central Office",
    ip: "10.0.20.5", mode: "active", version: "7.0.4", encrypted: "Cert",
    status: "down", lastSeen: "14m 02s",
    hosts: 124, items: 11412, nvps: 0, queue: 218,
    cpu: 0, mem: 0, db: "—",
    notes: "Unreachable · last conn 14m ago · 218 items queued",
  },
];

// Synthetic 60-min timelines
const range = (n, min, max) => Array.from({ length: n }, (_, i) => {
  const t = i / (n - 1);
  const wave = Math.sin(t * Math.PI * 2.4) * 0.18 + Math.sin(t * Math.PI * 5.1) * 0.08;
  const noise = (Math.sin(i * 1.7) + Math.cos(i * 2.3)) * 0.04;
  return Math.round(min + (max - min) * (0.55 + wave + noise));
});

const ZBX_NVPS_TIMELINE  = range(60, 6800, 8400);
const ZBX_QUEUE_TIMELINE = (() => {
  const a = range(60, 80, 180);
  // bump the last ~10m to reflect Central Office outage
  for (let i = 50; i < 60; i++) a[i] = a[i] + (i - 50) * 18;
  return a;
})();
const ZBX_CACHE_TIMELINE = range(60, 58, 78);

// Recent service events (server + proxies)
const ZBX_EVENTS = [
  { ts: "09:38:14", src: "zbx", host: "zbx-proxy-tcs-08", sev: "high", msg: "Proxy unreachable — ", obj: "no data for 14m, 218 items queued" },
  { ts: "09:36:02", src: "zbx", host: "zbx-srv-01",       sev: "warn", msg: "Housekeeper busy — ",   obj: "%busy = 93 (5m avg), partitioning lag 2.1h" },
  { ts: "09:34:51", src: "zbx", host: "zbx-proxy-tcs-04", sev: "warn", msg: "Proxy queue growing — ", obj: "47 items > 10m on TCTA pollers" },
  { ts: "09:31:18", src: "zbx", host: "zbx-srv-01",       sev: "warn", msg: "Trigger housekeeper — ", obj: "%busy = 84 (5m avg)" },
  { ts: "09:24:09", src: "zbx", host: "zbx-proxy-tcs-07", sev: "info", msg: "Version mismatch — ",   obj: "Arcadia proxy on 6.4.18, server is 7.0.4" },
  { ts: "09:18:43", src: "zbx", host: "zbx-srv-02",       sev: "ok",   msg: "HA heartbeat — ",       obj: "standby OK, last failover 0d 0h ago" },
  { ts: "09:12:27", src: "pf",  host: "pf-01",            sev: "ok",   msg: "PacketFence reachable — ", obj: "via zbx-proxy-tcs-01" },
  { ts: "09:08:11", src: "zbx", host: "zbx-srv-01",       sev: "ok",   msg: "Configuration cache reload — ", obj: "1,842 hosts, 184,726 items in 1.84s" },
  { ts: "09:02:00", src: "zbx", host: "zbx-srv-01",       sev: "info", msg: "Auto-registration — ",  obj: "1 host: NRH-08-Gym (172.18.4.42)" },
  { ts: "08:54:38", src: "zbx", host: "zbx-proxy-tcs-02", sev: "ok",   msg: "Proxy data sent — ",    obj: "33,104 items / 6s window" },
];

window.ZBX_SUMMARY        = ZBX_SUMMARY;
window.ZBX_NODES          = ZBX_NODES;
window.ZBX_PROCESSES      = ZBX_PROCESSES;
window.ZBX_CACHES         = ZBX_CACHES;
window.ZBX_PROXIES        = ZBX_PROXIES;
window.ZBX_NVPS_TIMELINE  = ZBX_NVPS_TIMELINE;
window.ZBX_QUEUE_TIMELINE = ZBX_QUEUE_TIMELINE;
window.ZBX_CACHE_TIMELINE = ZBX_CACHE_TIMELINE;
window.ZBX_EVENTS         = ZBX_EVENTS;
