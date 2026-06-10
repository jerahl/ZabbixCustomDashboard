# Build brief — Milestone data acquisition rework

Executable, phased plan to move Milestone status/inventory off external shell
scripts onto: (1) native Zabbix REST SCRIPT items for inventory, (2) a single
WebSocket collector for monitoring state, and (3) a browser WebSocket client for
the live NOC UI.

**Read first:** [`milestone-rest-rework.md`](milestone-rest-rework.md) (design
rationale + endpoint facts). This brief is the build sequence; that doc is the
"why".

---

## Orientation (do not re-derive)

**Repo / layout** — everything lives in `jerahl/ZabbixCustomDashboard`:

- `tcs_dashboard/zabbix/milestone/` — **acquisition**: the Zabbix templates,
  the existing external scripts being removed, the future `collector/` and
  `test/fixtures/`. The base template export (`Milestone XProtect by HTTP` +
  camera templates) is versioned here at
  `templates/milestone_by_http_api.yaml`.
- `tcs_dashboard/` (controllers, views, JSX bridges) — **consumption**: the PHP
  module. No Milestone PHP client exists yet.

**APIs (all on the API Gateway host)**

- Token: `POST {scheme}://{host}/API/IDP/connect/token`,
  `Content-Type: application/x-www-form-urlencoded`,
  body `grant_type=password&username=…&password=…&client_id=GrantValidatorClient`.
  Response has `access_token` + `expires_in`.
- Config REST base: `{scheme}://{host}/API/rest/v1`.
- Events & State WebSocket: `{ws|wss}://{host}/api/ws/events/v1`.

**Hard facts that constrain design**

- The Gateway camera object is **config only** — no live state, no
  `/cameras/{id}/state`. Live state comes from the WS API only.
- `GET /cameras` has **no pagination** (only `?disabled=`). Stage per recording
  server: `GET /recordingServers/{id}/hardware` → `GET /hardware/{id}/cameras`.
  Config-API `page`/`size` support is **undocumented** (the documented paging is
  the Events REST API's) — Phase 0 probes it before payload sizing.
- WS session resumes within a **30s** window via `sessionId` + last `eventId`,
  recovering missed events. New session (`startSession` status 201) requires
  re-`addSubscription`; resumed (200) does not. A **failed resume returns a
  plain 201** — branch on the status code, never assume the resume worked.
- Event/state `source` is `"cameras/<guid>"` — prefix-strip before keying.
- `getState` at ~2,560 cameras takes **1–2 minutes** to first byte and needs
  `max_size` ≈ 128 MB on the WS client (see `milestone_ess_state.py`).
- Browser `WebSocket` can't set headers → auth is the in-band
  `{command:'authenticate', token:'Bearer …'}` message, not the connect header.
  The WS handshake is not subject to CORS preflight; only the token `fetch` is.

**Event-type GUIDs for the subscription** (from sample `event_types.py`)

| Meaning | GUID |
|---|---|
| communication_started | `dd3e6464-7dc0-405a-a92f-6150587563e8` |
| communication_stopped | `0ee90664-2924-42a0-a816-4129d0ecabdc` |
| communication_error | `a334af1c-4b4b-4957-9e5f-ab8ca07feab6` |
| recording_started | `4577f552-765a-438c-bc7d-e5ff1f754bc3` |
| recording_stopped | `79a94f89-92de-4fca-8a43-5561d407423d` |

`communication_started` already matches the template macro
`{$MILESTONE.ESS.TYPE.COMMUNICATION_OK}`.

> **Coverage caveat:** `event_types.py` also defines hardware-level comm events
> (`communication_hw_*`), motion, and live-feed-terminated. Today's pipeline
> subscribes `eventTypes:["*"]` and dedupes by `stategroupid`; narrowing to the
> 5 GUIDs above is gated on the Phase 0 stategroup coverage audit.

**Existing template item keys to preserve** (consumers depend on these)

`milestone.cam.ess.comm.type[{#CAM.ID}]`, `milestone.cam.ess.comm.time[{#CAM.ID}]`,
`milestone.cam.ess.rec.type[{#CAM.ID}]`, `milestone.cam.ess.raw[{#CAM.ID}]`,
`milestone.cam.status[{#CAM.ID}]` (CALCULATED), `milestone.cam.alarm[{#CAM.ID}]`
(CALCULATED). Repoint their data source; **do not rename keys**.

Cameras are LLD **item prototypes on the one Milestone host** (not host
prototypes): trapper targeting is `<milestone-host> <key[guid]> <value>` —
no GUID→host mapping table.

---

## Cross-cutting guardrails (apply in every phase)

- **Secrets never in git, never in client JS.** Use Zabbix secret macros
  (`{$MILESTONE.PASSWORD}`) and a server-side secrets file for the collector.
  The browser receives only a short-lived token minted server-side — never the
  VMS password.
- **Never set `Access-Control-Allow-Origin: *`** on the production Gateway.
- **Don't touch the working AP Detail pipeline** or unrelated dashboard pages.
- **Keep item keys stable** (above). Schema-breaking changes ripple into the PHP
  controllers and CALCULATED items.
- **Test against dev XProtect first.** No production cutover until Phase 3 gate.
- Each phase is a self-contained work session with its own Definition of Done.
  Do not start a phase until the prior phase's DoD is met.

---

## Phase 0 — Dev harness & API verification

**Objective:** prove the three API surfaces work against dev XProtect before
writing any integration code.

**Tasks**
1. Create a dedicated basic VMS user (read-only role sufficient for events +
   config GET). Record host, scheme, creds in a local untracked `.env`.
2. Verify token: `curl -sk -X POST .../API/IDP/connect/token -d 'grant_type=…'`
   → confirm `access_token` + note `expires_in`.
3. Verify config: `GET /API/rest/v1/recordingServers`, then
   `GET /recordingServers/{id}/hardware`, then `GET /hardware/{id}/cameras`.
   Capture one full camera object and one RS object as fixtures.
4. Verify WS: connect with `websocat`/`wscat` using the bearer header, send
   `startSession` (blank ids), `addSubscription` (`resourceTypes:["cameras"]`,
   the 5 GUIDs above), `getState`; confirm a baseline arrives and that toggling
   a camera produces a delta event. Capture sample messages as fixtures.
5. Note Gateway behavior on the WS `Origin` header (for Phase 4 planning).
6. **Pagination probe:** request `GET /hardware?page=0&size=2` (and the same on
   `/cameras`) — determine whether the Config API honors paging or silently
   ignores it. Record the answer; Phase 1 payload sizing depends on it.
7. **Stategroup coverage audit:** run
   `milestone_ess_state.py --list-stategroups` piped through
   `milestone_ess_resolve.py` on dev; confirm the 5 subscription GUIDs cover
   every stategroup the template's macros/CALCULATED items read. Decide whether
   `resourceTypes` needs `"hardware"` (for `communication_hw_*` events).
8. **Synthetic-event check:** `POST /events` with a comm/recording `type` GUID
   and `source:"cameras/{id}"` (service token likely required — user tokens can
   only trigger External/MIPDevice generator types). Record whether synthetic
   events appear in the WS *state* stream or only the event log — this decides
   how Phase 2's DoD can be tested without physically toggling cameras.
9. **Resume-window confirmation:** kill the WS, resume with stored
   `sessionId`+`eventId` at ~25s and ~35s; confirm 200 vs 201. The 30s figure
   is from the ESS docs, not observable in the sample/specs.

**Definition of Done:** token, per-RS camera enumeration, and a live WS delta
all observed and captured as fixtures in
`tcs_dashboard/zabbix/milestone/test/fixtures/`; answers recorded for tasks
6–9.

**Out of scope:** any Zabbix or dashboard changes.

---

## Phase 1 — Inventory plane: native REST SCRIPT items, staged per RS

**Objective:** replace the config external scripts with native Zabbix 7.4 SCRIPT
items; produce the camera LLD that everything else keys on. No `ExternalScripts`.

**Tasks**
1. Rename/curate the versioned base export to
   `templates/milestone_by_http_api.yaml` (done). Work against that file;
   re-import to dev to test.
2. **Camera plane — single native SCRIPT aggregator** (chosen 2026-06 over the
   original per-RS-masters sketch; see "Why not per-RS item prototypes" below).
   Add ONE regular SCRIPT item `milestone.cameras.getall` that does token →
   loop recording servers → `GET /recordingServers/{RS.ID}/hardware?includeChildren=cameras,settings`
   (one call **per RS**, *not* per hardware) → assemble the **exact legacy
   shape** `{__count, __fetched_at, __array:[…], "<guid>":{…}}` the old
   `milestone_cameras_read.sh` produced, with the KEEP field set from
   `milestone_cameras_state.py` (id, displayName, enabled, address, mac,
   hardwareId, hardwareName, hardwareModel, channel, lastModified,
   recordingServerId, groupName, relations). `groupName` still needs a join
   against `GET /cameraGroups` membership — fetch once and map.
   **`includeChildren` is used deliberately here** (the documented escape
   hatch): per-hardware `/hardware/{id}/cameras` fan-out is O(hardware-count)
   sequential GETs and blows the SCRIPT timeout at fleet scale; RS-scoped
   includeChildren collapses it to one call per RS. Keep a per-hardware
   fallback path (as the python has) for API versions that don't embed
   children.
3. **Repoint, don't rewire.** Point `milestone.cameras.discovery` (LLD) and
   `milestone.cam.raw[{#CAM.ID}]` at `milestone.cameras.getall`; all other
   `milestone.cam.<config>[{#CAM.ID}]` dependents already hang off
   `milestone.cam.raw` and need no change. Shape is byte-compatible, so
   `ActionSurveillanceData.php` (which back-fills from `__array`) is untouched.

   **Why not per-RS item prototypes (the original sketch):** Zabbix forbids a
   dependent item *prototype* from having a master that is a prototype of a
   *different* LLD rule. Per-RS camera fetchers would be prototypes of the **RS**
   LLD; the per-camera dependents are prototypes of the **camera** LLD — so they
   cannot legally depend on per-RS masters. Keeping the `milestone.cam.*` keys
   stable (a hard guardrail) therefore forces a single regular master holding
   the whole fleet, exactly as today. The per-RS benefit is preserved where it
   matters — the **API fetch** is RS-scoped (bounded calls, no single monster
   request) — only the final assembled value is fleet-sized (history 0, read
   only by the LLD + dependents).

   > **Gated on Phase 0 (held 2026-06):** the SCRIPT is not written until Phase
   > 0 confirms (a) `GET /recordingServers/{id}/hardware?includeChildren=cameras,settings`
   > actually embeds cameras *and* the MAC setting per RS, and (b) the per-RS
   > call completes well inside the SCRIPT timeout at the largest RS. See
   > `test/` for the probe that captures these.
4. Replace `milestone_groups_read.sh` with SCRIPT `milestone.groups.get`
   (`GET /cameraGroups`). Replace `milestone_rs_read.sh` per the RS-extras
   disposition (rework doc §2a): storage rollups + per-storage LLD via
   `GET /recordingServers/{id}/storages` SCRIPT chain; camera/hardware counts
   derived from the per-RS camera masters; RS service state deferred to the
   Phase 2 collector.
5. Set inventory cadence to 1h (or longer). Remove the now-dead EXTERNAL config
   items from the template.

**Definition of Done:** template imports clean on dev 7.4; camera LLD discovers
the full fleet via `milestone.cameras.getall`; per-camera **config** items
populate with byte-compatible values (diffed against the old external snapshot
during parity); the Servers/Storage tabs and Sites storage bar still render
(RS-extras parity); zero `milestone_*_read.sh` references remain for config.
The heaviest **API call** is RS-scoped (no whole-fleet single request); the
aggregator's stored value is whole-fleet by necessity (history 0) — the
original "per-RS slice stored value" goal was dropped once the cross-LLD master
constraint made per-RS prototypes unusable for the camera dependents.

**Out of scope:** ESS/status items (still fed by the old `milestone_ess_read.sh`
until Phase 3 — leave it running for now), dashboard.

---

## Phase 2 — Collector service: WS → Zabbix trappers (monitoring plane)

**Objective:** one durable service that holds the WS, baselines via `getState`,
streams deltas, and pushes per-camera state into Zabbix trapper items.

**Seed code:** start from the in-repo `milestone_ess_state.py`, **not** the
Milestone sample — it already encodes the production lessons (rework doc §4a):
`max_size` 128 MB, the `getState`-vs-keepalive-ping interplay, the
`websockets` >=13/<13 header-kwarg shim, `source` prefix-stripping, and
`stategroupid` dedup (`by_group`).

**Tasks**
1. Adapt into `collector/milestone_collector.py`:
   - token fetch + **background refresh** before `expires_in`; apply fresh token
     on next reconnect (the Milestone sample has neither — both are new work).
   - connect loop with reconnect/backoff; `startSession` resume via stored
     `sessionId` + `lastEventId`; **branch on status**: 201 (new *or* failed
     resume) → `addSubscription` (filter per Phase 0 task 7 outcome) +
     `getState` baseline; 200 → continue.
   - **read-pump design**: keepalive pings must be serviced while the 1–2 min
     `getState` baseline assembles — a long-lived collector can't run with
     `ping_interval=None` like the one-shot script does. This is the phase's
     main engineering risk; design it first.
   - strip the `cameras/` prefix from `source`; classify comm vs. rec via
     `stategroupid`; emit `zabbix_sender` lines against the single Milestone
     host (`<host> milestone.cam.ess.comm.type[<guid>] <value>`).
2. Add **trapper** item prototypes to the template, repointing the existing keys:
   `milestone.cam.ess.comm.type[{#CAM.ID}]`, `…comm.time`, `…rec.type`,
   `…ess.raw` → type **Zabbix trapper**, fed by the collector. Keep
   `milestone.cam.status` / `…alarm` CALCULATED unchanged. Add the RS
   service-state trapper (RS-extras disposition).
3. Config via env/secrets file (host, scheme, creds, Zabbix server addr, sender
   host-name strategy). Structured logging; exit non-zero on fatal.
4. Provide a `systemd` unit (`collector/milestone-collector.service`) and a
   `--once` / dry-run mode that prints sender lines without sending.

**Definition of Done:** toggling a camera's comm/recording state on dev — or,
if Phase 0 task 8 confirmed it reaches the WS stream, a synthetic
`POST /events` trigger — shows up in the corresponding Zabbix trapper item
within seconds; killing and restarting the collector recovers state (resume
< 30s, else `getState` re-baseline); a status trigger fires/clears correctly.
Optionally load-test the sender fan-out with `POST /events/bulk`.

**Out of scope:** decommissioning old ESS script (Phase 3), dashboard.

---

## Phase 3 — Cutover & decommission (production gate)

**Objective:** retire all external scripts; collector + REST become the sole
source. Reversible.

**Tasks**
1. Run collector + native template **in parallel** with the old
   `milestone_ess_read.sh` on dev; diff state values for a soak period.
2. On parity, remove `milestone_ess_read.sh` and any remaining
   `milestone_*_read.{sh,py}` from template and `ExternalScripts`.
3. Decommission sweep — run against the **live** `externalscripts/` dir and
   crontab, not just git (at least one deployed script,
   `milestone_groups_state.py`, was never in the repo): remove cron entries,
   `/var/lib/zabbix/milestone_*.json` snapshots, and the matching
   `.err`/`.lock`/log files.
4. Confirm `grep -r ExternalScripts` / template has **zero** Milestone external
   refs. Update the milestone README + the dashboard integration-plan §3c (the
   "deploy 8 scripts" step is gone).
5. Production rollout: import template, deploy collector service, set secret
   macros. Keep the old scripts archived (git tag) for rollback.

**Definition of Done:** production runs with no Milestone external scripts (and
no orphaned cron entries or snapshot files); inventory + status both healthy;
rollback path documented.

**Out of scope:** dashboard (Phase 4).

---

## Phase 4 — Live UI plane: server-minted token + browser WS bridge

**Objective:** take Surveillance NOC / Camera Detail / Recording Server pages off
mock data with a live browser WS feed, without exposing VMS credentials.

**Tasks**
1. Build `lib/MilestoneClient.php` (~250 lines): server-side token mint
   (service account, `grant_type=password`), short-TTL token returned to the
   browser; also the per-RS REST helpers reused for thumbnails. Creds from
   `Config::milestone()` (mirror existing PF/XIQ config pattern).
2. Extend `actions/ActionSurveillanceData.php`: returns initial payload (camera
   list + last-known state from Zabbix items) **and** a freshly minted WS token.
3. Build `assets/surveillance-ws.jsx`: open `wss://{host}/api/ws/events/v1`,
   send `authenticate(token)` → `startSession` → `addSubscription`
   (`resourceTypes:['cameras']`, the 5 GUIDs) → render deltas into
   `window.MILESTONE` / `window.SITES` (names already used by `nvr-data.jsx`).
   Reconnect with backoff, resume via `sessionId`+`eventId`.
4. Wire `ActionSurveillance` / `ActionCamera` / `ActionServer` to the live
   bridge; filter Camera/RS detail by `id` query param (shared payload).
5. **Before cross-origin:** verify Phase-0 Gateway `Origin` finding. If rejected,
   reverse-proxy the dashboard same-origin or scope CORS to the dashboard origin
   only. Never `ACAO:*`.

**Definition of Done:** NOC tiles update live as camera state changes; VMS
password never appears in client JS or network payloads (only a short-lived
token); detail views render from the shared payload; pages off mock data.

**Out of scope:** camera-wall video thumbnails beyond a static snapshot
(separate effort).

---

## Phase 5 — Hardening & operations

**Objective:** make the collector and bridge production-grade and observable.

**Tasks**
1. **Monitor the monitor:** Zabbix item + trigger on collector liveness
   (heartbeat trapper or systemd watchdog) — alert if state stops flowing.
2. Token/secret rotation runbook; verify behavior across token expiry, Gateway
   restart, and network blips (forced reconnect + re-baseline).
3. Collector metrics: events/sec, reconnect count, last-baseline time, send
   failures. Log rotation. Backoff caps.
4. Docs: collector README (install, systemd, config, secrets,
   troubleshooting); update the dashboard README for the surveillance pages and
   CORS posture.

**Definition of Done:** a deliberately induced Gateway restart and a token
expiry both auto-recover with no manual action and ≤ one resume gap; collector
downtime raises a Zabbix alert; runbook covers rotation and rollback.

---

## Dependency order

`0 → 1 → 2 → 3` is strict (inventory LLD `{#CAM.ID}` underpins the collector's
item keying; cutover gates on parity). `4` depends only on `0` + `1` (it needs
the camera list and the token pattern) and can run in parallel with `2`/`3`.
`5` follows `3` and `4`.

---

## Fallback (documented, not planned)

If a collector service is ever disallowed, the REST Events API
`/eventSessions` polling fallback exists but is strictly inferior (rework doc
§4c) — and it only returns **stored** events: the event-type retention policy
(Management Client → Tools → Options → Alarms and Events, ≥ 1 day) must be
configured or it returns empty arrays regardless of code correctness.
