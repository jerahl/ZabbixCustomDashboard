// Camera detail panel — single camera deep dive

// Resolve the camera the page is about. The live Surveillance tiles/rows
// link by &hostid=<perCameraZabbixHost>; older links pass ?id=<camId>.
// camera-bridge.jsx publishes the server-resolved camera as CAMERAS[0].
const resolveCamera = () => {
  const params = new URLSearchParams(location.search);
  const hostid = params.get("hostid");
  const id     = params.get("id");
  const cams   = window.CAMERAS || [];
  if (hostid) { const m = cams.find(c => String(c.hostid) === hostid); if (m) return m; }
  if (id)     { const m = cams.find(c => c.id === id);                 if (m) return m; }
  return cams[0] || null;
};

const CameraDetailEmpty = () => (
  <div className="app">
    <NVRSidebar active="nvr-cameras" />
    <div className="main">
      <NVRTopbar crumb={["Surveillance", "Cameras", "—"]} />
      <div className="body">
        <div className="card" style={{ padding: 40, textAlign: "center", color: "var(--muted)" }}>
          <h3 style={{ marginBottom: 8 }}>Camera not found</h3>
          <div style={{ fontSize: 13 }}>
            No live camera matched this link. It may not be discovered in Zabbix yet,
            or the host id is stale. <a className="cam-id-link" href="zabbix.php?action=tcs.surveillance.view&view=cameras">Back to cameras</a>
          </div>
        </div>
      </div>
    </div>
  </div>
);

const CameraDetail = () => {
  const cam = resolveCamera();
  const [tab, setTab] = React.useState("overview");
  if (!cam) return <CameraDetailEmpty />;

  const camName = cam.name || cam.id;
  const hasIp = cam.ip && cam.ip !== "—";
  // Camera web UI live view — embeds the device's own fullscreen stream page.
  const liveUrl = hasIp
    ? `http://${cam.ip}/fullscreen.htm?line=1&stream=1&vport=2&autoresize=false&keepaspect=true&dewarp=false`
    : null;

  const H = window.CAM_HISTORY || {};
  const liveEvents = window.CAM_EVENTS || [];

  const show   = (...tabs) => tabs.includes(tab);
  const lastOf = (a) => (Array.isArray(a) && a.length ? a[a.length - 1] : 0);
  const fmt    = (v) => (typeof v === "number" ? (Number.isInteger(v) ? v : v.toFixed(1)) : v);

  const isErr = cam.state === "err";
  const isWarn = cam.state === "warn";
  const stateLabel = isErr ? "Offline" : isWarn ? "Warning" : "Streaming";
  const stateColor = isErr ? "var(--err)" : isWarn ? "var(--warn)" : "var(--ok)";

  const now = new Date();
  const ts = now.toISOString().replace("T", " ").substr(0, 19);

  return (
    <div className="app">
      <NVRSidebar active="nvr-cameras" />
      <div className="main">
        <NVRTopbar crumb={["Surveillance", "Cameras", cam.site, camName]} />

        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }} onClick={() => history.back()}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>{camName}</h1>
              <span className="ip">{cam.ip}</span>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>{cam.model}</span>
            </div>
            <div className="host-meta">
              <span className="pill"><span className="dot" style={{ background: stateColor }} /> {stateLabel}</span>
              <span className="pill"><span className="lbl">Site</span> <span>{cam.site} · {cam.loc}</span></span>
              <span className="pill"><span className="lbl">Recording</span> <span className="v">{cam.recording}</span></span>
              <span className="pill"><span className="lbl">Server</span> {cam.server&&cam.server!=="—"?<a className="cam-id-link" href={`zabbix.php?action=tcs.server.view&id=${encodeURIComponent(cam.server)}`}>{cam.server}</a>:<span className="v">—</span>}</span>
              <span className="pill"><span className="lbl">MAC</span> <span className="v">{cam.mac}</span></span>
            </div>
          </div>
          <div className="timerange"><Icon name="calendar" /><span className="range-val">last 24h</span><Icon name="chevron" /></div>
        </div>

        <div className="tabs">
          {[["overview","Overview"],["live","Live"],["recordings","Recordings"],["events","Events"],["health","Health"],["config","Configuration"]].map(([k,l]) =>
            <div key={k} className={"tab " + (tab===k?"active":"")} onClick={()=>setTab(k)}>{l}</div>
          )}
        </div>

        <div className="body" data-screen-label={`Camera · ${camName}`}>
          <DemoBanner name="Camera Detail" />
          <div style={{ display: "grid", gridTemplateColumns: "320px 1fr", gap: 14 }}>
            {/* Sidecar */}
            <div className="card">
              <div className="device-hero" style={{padding:14}}>
                <div className="status-line"><StatusDot state={cam.state==="err"?"err":cam.state==="warn"?"warn":"ok"}/> <span style={{color:stateColor}}>{stateLabel}</span></div>
                <div className="live-large" style={{ width: "100%", marginTop: 12 }}>
                  <div className="frame"/><div className="scan"/>
                  {!isErr && liveUrl && (
                    <iframe
                      src={liveUrl}
                      title={`Live view · ${camName}`}
                      allow="autoplay; fullscreen"
                      style={{ position: "absolute", inset: 0, width: "100%", height: "100%", border: 0, background: "#000" }}
                    />
                  )}
                  {!isErr ? <>
                    <div style={{position:"absolute",top:8,left:10,fontFamily:"var(--mono)",fontSize:10,color:"#fff"}}>{cam.id}</div>
                    <div style={{position:"absolute",top:8,right:10,fontFamily:"var(--mono)",fontSize:10,color:"rgba(255,255,255,0.85)"}}>{ts}</div>
                    {(cam.res!=="—"||cam.fps||cam.codec!=="—") && <div style={{position:"absolute",bottom:8,left:10,fontFamily:"var(--mono)",fontSize:10,color:"rgba(255,255,255,0.85)"}}>{[cam.res!=="—"?cam.res:null,cam.fps?`${cam.fps}fps`:null,cam.codec!=="—"?cam.codec:null].filter(Boolean).join(" · ")}</div>}
                    <div style={{position:"absolute",bottom:8,right:10,display:"flex",alignItems:"center",gap:4,fontFamily:"var(--mono)",fontSize:10,color:"#fff"}}>
                      <span style={{width:7,height:7,borderRadius:50,background:"var(--err)",animation:"blink 1.4s infinite",boxShadow:"0 0 6px var(--err)"}}/> REC
                    </div>
                  </> : (
                    <div style={{position:"absolute",inset:0,display:"grid",placeItems:"center",color:"var(--err)",fontFamily:"var(--mono)",letterSpacing:2,fontWeight:600}}>NO SIGNAL</div>
                  )}
                </div>
                <div style={{ display: "flex", gap: 6, marginTop: 10, width: "100%" }}>
                  <button className="btn primary" style={{flex:1}}><Icon name="external" size={12}/> Smart Client</button>
                  <button className="btn" style={{flex:1}}><Icon name="refresh" size={12}/> Restart Stream</button>
                </div>
              </div>

              <div className="location-block">
                <div className="label">Location</div>
                <div className="v">{cam.site}<br/>{cam.loc}</div>
              </div>
              <div className="location-block">
                <div className="label">Hardware</div>
                <div className="v">
                  <div>{cam.model}</div>
                  <div className="muted" style={{fontSize:11}}>MAC {cam.mac}</div>
                  <div className="muted" style={{fontSize:11}}>PoE draw {cam.poe?`${cam.poe} W`:"—"}</div>
                </div>
              </div>
              <div className="location-block">
                <div className="label">Recording Server</div>
                <div className="v" style={{fontSize:11}}>{cam.server}</div>
              </div>
            </div>

            {/* Right column — content switches with the active tab */}
            <div>
              {/* Active issue — always shown when present, regardless of tab */}
              {(isErr || isWarn) && (
                <div className="card" style={{ marginBottom: 14, borderColor: isErr ? "rgba(242,95,92,0.5)" : "rgba(245,179,0,0.5)" }}>
                  <div className="card-h">
                    <h3 style={{ color: isErr ? "var(--err)" : "var(--warn)" }}>Active Issue</h3>
                    <SourceBadge src="zbx" />
                    <div className="h-spacer"/>
                    <button className="btn sm">Acknowledge</button>
                  </div>
                  <div style={{ padding: 14, fontSize: 13 }}>
                    {cam.errMsg || cam.warnMsg || "—"}
                  </div>
                </div>
              )}

              {/* Health rings — Overview, Live, Health */}
              {show("overview", "live", "health") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Stream Health</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">poll · 60s · ICMP + ONVIF status</span></div>
                <div className="health-grid">
                  <div className="health-cell">
                    <Ring value={isErr?0:cam.fps} max={30} label={fmt(cam.fps)} sub="FPS" color="var(--zbx)"/>
                    <div className="h-label">Frame Rate</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={isErr?0:cam.bitrate/100} max={120} label={cam.bitrate?(cam.bitrate/1000).toFixed(1):"—"} sub="Mbps" color="var(--info)"/>
                    <div className="h-label">Bitrate ({cam.codec})</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={lastOf(H.latency)} max={100} label={H.latency&&H.latency.length?fmt(lastOf(H.latency)):"—"} sub="ms" color="var(--zbx)"/>
                    <div className="h-label">ICMP Latency</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={lastOf(H.packetLoss)} max={100} label={H.packetLoss&&H.packetLoss.length?fmt(lastOf(H.packetLoss)):"—"} sub="%" color={lastOf(H.packetLoss)>1?"var(--warn)":"var(--ok)"}/>
                    <div className="h-label">Packet Loss</div>
                  </div>
                </div>
              </div>
              )}

              {/* Live telemetry — Overview, Health */}
              {show("overview", "health") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Live Telemetry · 24h</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">48 samples · 30m bucket</span></div>
                <div className="spark-strip">
                  <SparkCellM label="FPS" v={cam.fps||"—"} unit="" data={H.fps} color="var(--zbx)" />
                  <SparkCellM label="Bitrate" v={cam.bitrate?(cam.bitrate/1000).toFixed(1):"—"} unit="Mbps" data={H.bitrate} color="var(--info)" />
                  <SparkCellM label="Pkt Loss" v={H.packetLoss&&H.packetLoss.length?fmt(lastOf(H.packetLoss)):"—"} unit="%" data={H.packetLoss} color="var(--warn)" />
                  <SparkCellM label="ICMP Latency" v={H.latency&&H.latency.length?fmt(lastOf(H.latency)):"—"} unit="ms" data={H.latency} color="var(--zbx)" />
                </div>
              </div>
              )}

              {/* Stream configuration — Live, Configuration */}
              {show("live", "config") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Stream Configuration</h3><SourceBadge src="ext"/></div>
                <div className="kv tight">
                  <div className="k">Codec</div><div className="v">{cam.codec}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Resolution</div><div className="v">{cam.res}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">FPS</div><div className="v">{cam.fps||"—"}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Bitrate</div><div className="v">{cam.bitrate?`${(cam.bitrate/1000).toFixed(1)} Mbps`:"—"}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Recording mode</div><div className="v">{cam.recording}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Stream URL</div><div className="v" style={{fontSize:10}}>{liveUrl?<a className="cam-id-link" href={liveUrl} target="_blank" rel="noreferrer">{liveUrl}</a>:"—"}</div><div className="b"><SourceBadge src="ext"/></div>
                </div>
              </div>
              )}

              {/* Recording — Recordings */}
              {show("recordings") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Recording</h3><SourceBadge src="ext"/></div>
                <div className="kv tight">
                  <div className="k">Mode</div><div className="v">{cam.recording}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Server</div><div className="v">{cam.server&&cam.server!=="—"?<a className="cam-id-link" href={`zabbix.php?action=tcs.server.view&id=${encodeURIComponent(cam.server)}`}>{cam.server}</a>:"—"}</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Retention</div><div className="v">—</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Last frame</div><div className="v">{isErr ? "—" : "live"}</div><div className="b"><SourceBadge src="zbx"/></div>
                </div>
              </div>
              )}

              {/* Network + identity — Configuration */}
              {show("config") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Network & Identity</h3><SourceBadge src="zbx"/></div>
                <div className="kv">
                  <div className="k">IPv4</div><div className="v">{cam.ip}</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">MAC</div><div className="v">{cam.mac}</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">Model</div><div className="v">{cam.model}</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">PoE draw</div><div className="v">{cam.poe?`${cam.poe} W`:"—"}</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">Recording server</div><div className="v">{cam.server}</div><div className="b"><SourceBadge src="ext"/></div>
                </div>
              </div>
              )}

              {/* Live preview note — Live */}
              {show("live") && (
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Live View</h3><SourceBadge src="ext"/><div className="h-spacer"/>{liveUrl && <a className="cam-id-link" href={liveUrl} target="_blank" rel="noreferrer">Open in new tab</a>}</div>
                {!isErr && liveUrl ? (
                  <div className="live-large" style={{ width: "100%" }}>
                    <iframe
                      src={liveUrl}
                      title={`Live view · ${camName}`}
                      allow="autoplay; fullscreen"
                      style={{ position: "absolute", inset: 0, width: "100%", height: "100%", border: 0, background: "#000" }}
                    />
                  </div>
                ) : (
                  <div style={{ padding: 14, fontSize: 13, color: "var(--muted)" }}>
                    {isErr
                      ? "Camera is offline — no live stream available."
                      : "No IP address discovered for this camera, so the live stream can't be embedded. Use the Milestone Smart Client to view it."}
                  </div>
                )}
              </div>
              )}

              {/* Recent events — Overview, Events */}
              {show("overview", "events") && (
              <div className="card">
                <div className="card-h"><h3>Recent Events</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">Open Zabbix problems · this camera</span></div>
                <div className="events">
                  {liveEvents.length
                    ? liveEvents.map((e, i) => <CamEvent key={i} ts={e.ts} src={e.src} sev={e.sev} msg={e.msg} />)
                    : <div style={{ padding: 18, textAlign: "center", color: "var(--muted)", fontSize: 13 }}>No open problems on this camera.</div>}
                </div>
              </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

const CamEvent = ({ ts, src, sev, msg }) => (
  <div className="event">
    <div className="ts">{ts}</div>
    <div className={`src ${src === "ZBX" ? "zbx" : "pf"}`}>{src}</div>
    <Sev level={sev} />
    <div className="msg">{msg}</div>
  </div>
);

ReactDOM.createRoot(document.getElementById("root")).render(<CameraDetail />);
