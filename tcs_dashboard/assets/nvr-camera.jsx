// Camera detail panel — single camera deep dive

const CameraDetail = () => {
  const params = new URLSearchParams(location.search);
  const id = params.get("id") || "BHS-C-110";
  const cam = window.CAMERAS.find(c => c.id === id) || window.CAMERAS[0];
  const H = window.CAM_HISTORY;
  const [tab, setTab] = React.useState("overview");

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
        <NVRTopbar crumb={["Surveillance", "Cameras", cam.site, cam.id]} />

        <div className="page-header">
          <div className="icon-btn" style={{ marginTop: 4 }} onClick={() => history.back()}><Icon name="back" /></div>
          <div style={{ flex: 1 }}>
            <div className="host-title">
              <h1>{cam.id}</h1>
              <span className="ip">{cam.ip}</span>
              <span className="role-tag faculty" style={{ fontSize: 10, padding: "1px 8px" }}>{cam.model}</span>
            </div>
            <div className="host-meta">
              <span className="pill"><span className="dot" style={{ background: stateColor }} /> {stateLabel}</span>
              <span className="pill"><span className="lbl">Site</span> <span>{cam.site} · {cam.loc}</span></span>
              <span className="pill"><span className="lbl">Recording</span> <span className="v">{cam.recording}</span></span>
              <span className="pill"><span className="lbl">Server</span> <a className="cam-id-link" href={`Server Detail.html?id=${cam.server}`}>{cam.server}</a></span>
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

        <div className="body" data-screen-label={`Camera · ${cam.id}`}>
          <div style={{ display: "grid", gridTemplateColumns: "320px 1fr", gap: 14 }}>
            {/* Sidecar */}
            <div className="card">
              <div className="device-hero" style={{padding:14}}>
                <div className="status-line"><StatusDot state={cam.state==="err"?"err":cam.state==="warn"?"warn":"ok"}/> <span style={{color:stateColor}}>{stateLabel}</span></div>
                <div className="live-large" style={{ width: "100%", marginTop: 12 }}>
                  <div className="frame"/><div className="scan"/>
                  {!isErr ? <>
                    <div style={{position:"absolute",top:8,left:10,fontFamily:"var(--mono)",fontSize:10,color:"#fff"}}>{cam.id}</div>
                    <div style={{position:"absolute",top:8,right:10,fontFamily:"var(--mono)",fontSize:10,color:"rgba(255,255,255,0.85)"}}>{ts}</div>
                    <div style={{position:"absolute",bottom:8,left:10,fontFamily:"var(--mono)",fontSize:10,color:"rgba(255,255,255,0.85)"}}>{cam.res} · {cam.fps}fps · {cam.codec}</div>
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
                <div className="v">{cam.site}<br/>{cam.loc}<br/>Mounted ceiling · IK10 housing</div>
              </div>
              <div className="location-block">
                <div className="label">Hardware</div>
                <div className="v">
                  <div>{cam.model}</div>
                  <div className="muted" style={{fontSize:11}}>MAC {cam.mac}</div>
                  <div className="muted" style={{fontSize:11}}>PoE Class 4 · {cam.poe}W</div>
                </div>
              </div>
              <div className="location-block">
                <div className="label">Zabbix Templates</div>
                <div className="v" style={{display:"flex",flexDirection:"column",gap:3,fontSize:11}}>
                  <span>• ICMP Ping (1m)</span>
                  <span>• ONVIF Camera via HTTP</span>
                  <span>• Milestone Camera State</span>
                </div>
              </div>
              <div className="location-block">
                <div className="label">Linked Switch Port</div>
                <div className="v" style={{fontSize:11}}>BHS-IDF1-SW02 · Gi1/0/14<br/><span className="muted">PoE+ · VLAN 60 (cameras)</span></div>
              </div>
            </div>

            {/* Right column */}
            <div>
              {/* Active warnings */}
              {(isErr || isWarn) && (
                <div className="card" style={{ marginBottom: 14, borderColor: isErr ? "rgba(242,95,92,0.5)" : "rgba(245,179,0,0.5)" }}>
                  <div className="card-h">
                    <h3 style={{ color: isErr ? "var(--err)" : "var(--warn)" }}>Active Issue</h3>
                    <SourceBadge src={isErr ? "zbx" : "ext"} />
                    <div className="h-spacer"/>
                    <button className="btn sm">Acknowledge</button>
                  </div>
                  <div style={{ padding: 14, fontSize: 13 }}>
                    {cam.errMsg || cam.warnMsg}
                  </div>
                </div>
              )}

              {/* Health rings */}
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Stream Health</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">poll · 60s · ONVIF status + RTSP probe</span></div>
                <div className="health-grid">
                  <div className="health-cell">
                    <Ring value={isErr?0:cam.fps} max={30} label={`${cam.fps}`} sub="FPS" color={cam.fps < cam.fps*0.9 ? "var(--warn)" : "var(--zbx)"}/>
                    <div className="h-label">Frame Rate · target {cam.fps || 25}</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={isErr?0:cam.bitrate/100} max={120} label={(cam.bitrate/1000).toFixed(1)} sub="Mbps" color="var(--info)"/>
                    <div className="h-label">Bitrate ({cam.codec})</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={28} max={100} label="28%" sub="cpu" color="var(--ok)"/>
                    <div className="h-label">Camera CPU</div>
                  </div>
                  <div className="health-cell">
                    <Ring value={42} max={75} label="42°" sub="C" color="var(--ok)"/>
                    <div className="h-label">Internal Temp</div>
                  </div>
                </div>
              </div>

              {/* Live telemetry */}
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Live Telemetry · 24h</h3><SourceBadge src="zbx"/><div className="h-spacer"/><span className="h-meta">48 samples · 30m bucket</span></div>
                <div className="spark-strip">
                  <SparkCellM label="FPS" v={cam.fps} unit="" data={H.fps} color="var(--zbx)" />
                  <SparkCellM label="Bitrate" v={(cam.bitrate/1000).toFixed(1)} unit="Mbps" data={H.bitrate} color="var(--info)" />
                  <SparkCellM label="Pkt Loss" v="0.02" unit="%" data={H.packetLoss} color="var(--warn)" />
                  <SparkCellM label="Motion / 30m" v={cam.motion12h} unit="" data={H.motion} color="var(--pf)" />
                </div>
                <div className="spark-strip" style={{borderTop:"1px solid var(--line)"}}>
                  <SparkCellM label="Camera CPU" v="28" unit="%" data={H.cpu} color="var(--ok)" />
                  <SparkCellM label="Internal Temp" v="42" unit="°C" data={H.temp} color="var(--ok)" />
                  <SparkCellM label="PoE Draw" v={cam.poe} unit="W" data={H.cpu} color="var(--info)" />
                  <SparkCellM label="ONVIF Latency" v="14" unit="ms" data={H.packetLoss.map(x=>10+x*40)} color="var(--zbx)" />
                </div>
              </div>

              {/* Stream info + Recording info */}
              <div className="row" style={{ gridTemplateColumns: "1fr 1fr", marginBottom: 14 }}>
                <div className="card">
                  <div className="card-h"><h3>Stream Configuration</h3><SourceBadge src="ext"/></div>
                  <div className="kv tight">
                    <div className="k">Codec</div><div className="v">{cam.codec}</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Resolution</div><div className="v">{cam.res}</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">FPS target</div><div className="v">{cam.fps || 25}</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Bitrate mode</div><div className="v">VBR · max {Math.round((cam.bitrate||5000)*1.4)} kbps</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">GOP / I-frame</div><div className="v">25 · 1s</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Audio</div><div className="v">G.711 · disabled</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Stream URL</div><div className="v" style={{fontSize:10}}>rtsp://{cam.ip}/onvif/profile1</div><div className="b"><SourceBadge src="ext"/></div>
                  </div>
                </div>
                <div className="card">
                  <div className="card-h"><h3>Recording</h3><SourceBadge src="ext"/></div>
                  <div className="kv tight">
                    <div className="k">Mode</div><div className="v">{cam.recording}</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Server</div><div className="v"><a className="cam-id-link" href={`Server Detail.html?id=${cam.server}`}>{cam.server}</a></div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Storage path</div><div className="v" style={{fontSize:10}}>D:\MilestoneRec\{cam.id}\</div><div className="b"><SourceBadge src="zbx"/></div>
                    <div className="k">Retention</div><div className="v">30 days · then archive</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Last frame</div><div className="v">{isErr ? "—" : "2s ago"}</div><div className="b"><SourceBadge src="zbx"/></div>
                    <div className="k">Last archive</div><div className="v">14m ago</div><div className="b"><SourceBadge src="ext"/></div>
                    <div className="k">Motion last 24h</div><div className="v">{cam.motion12h * 2} events</div><div className="b"><SourceBadge src="ext"/></div>
                  </div>
                </div>
              </div>

              {/* Network + identity */}
              <div className="card" style={{ marginBottom: 14 }}>
                <div className="card-h"><h3>Network & Identity</h3><SourceBadge src="zbx"/></div>
                <div className="kv">
                  <div className="k">IPv4</div><div className="v">{cam.ip} / 24</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">MAC</div><div className="v">{cam.mac}</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">VLAN</div><div className="v">60 (cameras)</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">LLDP / switch</div><div className="v">BHS-IDF1-SW02 · Gi1/0/14</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">PoE class</div><div className="v">Class 4 (802.3at) · {cam.poe}W</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">DNS</div><div className="v">cams.tcs.local · {cam.id.toLowerCase()}.cams.tcs.local</div><div className="b"><SourceBadge src="zbx"/></div>
                  <div className="k">ONVIF account</div><div className="v">milestone-svc · last auth 14s ago</div><div className="b"><SourceBadge src="ext"/></div>
                  <div className="k">Firmware</div><div className="v">11.5.65 · last upgrade 2026-02-12</div><div className="b"><SourceBadge src="ext"/></div>
                </div>
              </div>

              {/* Recent events */}
              <div className="card">
                <div className="card-h"><h3>Recent Events</h3><div className="h-spacer"/><span className="h-meta">Zabbix triggers + Milestone alarms</span></div>
                <div className="events">
                  <CamEvent ts="09:14:09" src="ZBX"  sev="info" msg={`ICMP echo OK · 1.4ms latency`} />
                  <CamEvent ts="08:47:11" src="EXT"  sev="info" msg="Motion event count: 24 in 30m bucket" />
                  <CamEvent ts="07:42:00" src="EXT"  sev="info" msg="Recording archived to long-term · 1.2 GB" />
                  <CamEvent ts="06:14:55" src="ZBX"  sev="info" msg="Bitrate stable at 4.8 Mbps for 12h" />
                  <CamEvent ts="Yesterday 18:14" src="EXT" sev="warning" msg="Motion event count anomaly (+187% vs 7d mean)" />
                  <CamEvent ts="Yesterday 03:00" src="ZBX" sev="info" msg="Daily firmware compliance check passed" />
                </div>
              </div>
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
