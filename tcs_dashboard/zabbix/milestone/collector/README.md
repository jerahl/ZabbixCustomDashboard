# milestone-collector

Long-lived Python service that owns all Milestone → Zabbix data acquisition:

- **WS pump (continuous)** — holds the Events-and-State WebSocket open,
  baselines via `getState`, streams deltas, pushes per-camera state to
  `milestone.cam.ess.raw[<guid>]` (the template's existing JS preprocessing
  fans the raw blob out to `…comm.type`, `…comm.time`, `…rec.type`).
- **REST pump (scheduled)** — pulls camera/groups/RS-storage inventory and
  pushes legacy-shape blobs to `milestone.cameras.getall`,
  `milestone.groups.get`, `milestone.rs.extras.get`.

One service, two pumps, one token-refresh loop, one secrets file — replaces
all eight legacy externals (`milestone_{cameras,groups,rs,ess}_{read,refresh,state}.{sh,py}`).

See `../milestone-rest-rework.md` for the design rationale and
`../milestone-rework-brief.md` Phase 2 for the operational decisions
(Zabbix-proxy host, 5-GUID subscription, systemd `EnvironmentFile=` secrets).

## Files

```
collector/
├── milestone_collector.py        # the service
├── milestone-collector.service   # systemd unit
├── requirements.txt              # aiohttp, websockets
└── README.md                     # this file
```

## Install (on the Zabbix proxy)

```bash
# 1. Dedicated unprivileged user.
sudo useradd --system --no-create-home --shell /usr/sbin/nologin milestone-collector

# 2. Python deps (a venv is fine if you don't want them system-wide).
sudo pip3 install -r requirements.txt

# 3. Drop the binary somewhere on PATH.
sudo install -m 0755 milestone_collector.py /usr/local/bin/milestone_collector.py

# 4. Secrets file — see ../test/.env.example for the variable set.
sudo install -d -o root -g root -m 0750 /etc/milestone-collector
sudo install -o root -g milestone-collector -m 0640 my.env /etc/milestone-collector/env

# 5. systemd unit.
sudo install -m 0644 milestone-collector.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now milestone-collector
sudo systemctl status milestone-collector
journalctl -u milestone-collector -f
```

## Required environment

```ini
# /etc/milestone-collector/env  (0640 root:milestone-collector)
MILESTONE_HOST=milestone.example.com
MILESTONE_SCHEME=https
MILESTONE_USER=zbx_collector
MILESTONE_PASSWORD=...           # XProtect Basic user, read-only role
MILESTONE_CLIENT_ID=GrantValidatorClient
MILESTONE_VERIFY_TLS=1           # 0 in dev for self-signed

ZABBIX_SERVER=zbx-proxy.example.com:10051   # zabbix_sender target
ZABBIX_SENDER_HOST=milestone-site-1         # Zabbix host the trapper items live on
# ZABBIX_SENDER_BIN=/usr/bin/zabbix_sender  # override if not on PATH
# LOG_LEVEL=DEBUG
```

The host name `ZABBIX_SENDER_HOST` is whichever Zabbix host has the
"Milestone XProtect by HTTP" and "Milestone XProtect RS extras by HTTP"
templates linked.

## Development / one-shot use

```bash
cd tcs_dashboard/zabbix/milestone/collector

# Dry-run against dev: load creds from ../test/.env, run both pumps once,
# print every sender line instead of shelling out.
./milestone_collector.py --once --dry-run --env ../test/.env

# REST plane only (verify inventory blob shape against the legacy externals).
./milestone_collector.py --once --rest-only --dry-run --env ../test/.env

# WS plane only (verify state baseline arrives).
./milestone_collector.py --once --ws-only --dry-run --env ../test/.env
```

`--once` runs each pump for a single full cycle (REST = one full assemble
+ push for each blob; WS = connect → subscribe → `getState` → exit before
the receive loop) and returns. Use it for parity diffs against the legacy
externals during the Phase 3 soak.

## Operational notes

- **Token refresh** runs lazily inside `TokenStore.get()` — the first call
  past `expires_in - 60s` triggers a refresh. The WS connection itself
  isn't reauthenticated mid-session (the protocol doesn't accept it); a
  fresh token is applied on the next reconnect. Tune the Milestone token
  TTL with care: if it's shorter than your typical reconnect interval, the
  WS will outlive its token and fail at the next forced reconnect.
- **WS resume** uses the stored `sessionId` + `lastEventId`. Milestone's
  30 s resume window recovers missed events; longer drops return a fresh
  `201` session, at which point the collector re-subscribes and re-baselines
  (the `getState` cost is one full snapshot every reconnect older than 30 s).
- **`getState` is huge** at fleet scale (~tens of MB, 1–2 min). The collector
  sets `max_size=128 MB` on the websocket and uses the library's default
  ping_interval so keepalives are serviced while the response streams.
- **Sender batching:** every WS event batch produces one `zabbix_sender`
  invocation (stdin-fed lines). REST blobs each produce one invocation.

## Liveness + metrics ("monitor the monitor")

The collector pushes one heartbeat per minute to `milestone.collector.heartbeat`
(TRAP) with all internal counters as one JSON blob:

```json
{
  "ts": 1781615200,
  "ws":     {"connected": true, "session": "...",
             "last_baseline_at": 1781615180, "reconnects": 3,
             "events_received": 41822, "events_applied": 612},
  "rest":   {"last_cameras_at": 1781614800, "last_groups_at": 1781614800,
             "last_rs_extras_at": 1781615100, "errors": 0},
  "sender": {"batches": 9412, "items": 23104, "failures": 0}
}
```

Dependent items on the template surface the load-bearing fields for trending:
`milestone.collector.ws.connected`, `…ws.reconnects`, `…ws.events`,
`…sender.failures`. Two triggers are wired:

- `nodata(milestone.collector.heartbeat, 5m) = 1` (HIGH) — the process is
  down, deadlocked, or can't reach the proxy. The state pipeline is blind.
- `last(milestone.collector.ws.connected) = 0` (AVERAGE) — heartbeats are
  still landing but the WS is detached. Usually self-heals within a minute
  or two; long stretches point at a Gateway or credentials problem.

Heartbeat runs as its own asyncio task — independent of both pumps. A wedged
pump still produces a heartbeat whose counters reveal the symptom (e.g.
`ws.connected=false`, `sender.failures` climbing), so the operator can
distinguish "process dead" from "process alive but stuck".

## Token / secret rotation

Rotation of the XProtect Basic user's password (or the user itself):

1. Provision the new credentials in XProtect Management Client. Verify the
   read-only role assignment is intact.
2. On the Zabbix proxy hosting the collector:
   ```bash
   sudo $EDITOR /etc/milestone-collector/env      # update MILESTONE_PASSWORD (and USER if rotated)
   sudo systemctl restart milestone-collector
   journalctl -u milestone-collector -f -n 20
   ```
   Expect `token refreshed via …` within ~1 s; then `WS up` after the
   reconnect; then the next heartbeat shows `ws.connected=true`.
3. Update the Zabbix global macro for the dashboard's browser bridge:
   Administration → General → Macros → `{$MILESTONE.PASSWORD}` (and
   `{$MILESTONE.USER}` if rotated). No service restart needed; the next
   `ActionSurveillanceData` summary poll mints a token with the new creds.
4. Verify on the Surveillance NOC page: DevTools console shows `[tcs-ws]
   handshake updated; reconnecting` → `WS up`. The 30 s
   `ActionSurveillanceData` poll picks the rotation up automatically; no
   page reload required.

If the Zabbix template macros are themselves rotated (e.g. the host moves):
update the four macros (`{$MILESTONE.HOST/SCHEME/USER/PASSWORD}`) AND
`/etc/milestone-collector/env` together. The two paths read independently —
the proxy via env, the dashboard via Zabbix API — so they can drift if only
one side is updated.

## Induced-failure tests (Phase 5 acceptance)

A clean Phase 5 deployment should pass each of these in a maintenance window:

- **Gateway restart:** the WS closes; collector logs `WS error: …`,
  schedules a backoff reconnect, then `WS up` and a fresh `getState`
  baseline. Heartbeat `ws.reconnects` increments by 1, `ws.connected`
  briefly 0 then 1.
- **Token expiry:** force by setting `MILESTONE_TOKEN_TTL_OVERRIDE`
  (TODO if/when supported) or simply wait past `expires_in`. On the next
  WS reconnect (forced or natural), `token refreshed via …` appears and
  the new token is applied. No manual intervention.
- **Network blip from proxy → Gateway:** drop the relevant route for
  60–90 s with `iptables`. Collector logs WS errors with backoff;
  recovers automatically when the route returns. `ws.connected` recovers.
- **Network blip from proxy → Zabbix:** `zabbix_sender` calls log
  `rc=…` errors and `sender.failures` increments; heartbeat itself
  bounces (the heartbeat sender is the same path). When connectivity
  returns, heartbeats resume; the 5-min nodata trigger may have fired
  if the gap exceeded its window, and it auto-clears.

## CORS / `Origin` posture (dashboard side)

Browser WebSocket handshakes do not honor CORS, but Milestone validates
the `Origin` header. Phase 0 task 5 confirms whether the Gateway accepts
the Zabbix-UI host's origin. If it does not:

- **Preferred:** put the dashboard behind a same-origin reverse proxy
  so the browser opens a same-host `wss://`.
- **Acceptable:** scope CORS on the Gateway to the exact dashboard
  origin only.
- **Never:** `Access-Control-Allow-Origin: *` on a production VMS.

## Known gaps (deferred)

- `MILESTONE_TOKEN_TTL_OVERRIDE` env knob isn't implemented — runbook
  refers to it as a hypothetical for token-expiry tests; in practice the
  next backoff reconnect already exercises the refresh path.
- Prometheus exposition: heartbeat JSON in Zabbix is the current
  interface; no `/metrics` endpoint. Add only if a Prom-based observability
  stack lands on the proxy.
