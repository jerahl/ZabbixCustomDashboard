# Milestone XProtect → Zabbix integration

This directory holds everything for the Milestone XProtect side of the TCS
dashboard: the Zabbix template, the Phase 2 collector service that replaces
the legacy externals, and the Phase 0 dev harness.

**Architecture (post-rework):** one Python service on the Zabbix proxy
(`collector/`) owns both the live ESS WebSocket and a daily REST poll
against the API Gateway, and pushes everything to **trapper** items on the
template. Eight legacy shell+Python externals collapse into that one
service. See `milestone-rest-rework.md` (the "why") and
`milestone-rework-brief.md` (the phased build sequence).

## Layout

```
tcs_dashboard/zabbix/milestone/
├── templates/
│   └── milestone_by_http_api.yaml        # versioned base + RS-extras template
├── collector/                            # Phase 2 service
│   ├── milestone_collector.py            # WS pump + REST pump + heartbeat
│   ├── milestone-collector.service       # systemd unit
│   ├── requirements.txt                  # aiohttp, websockets
│   └── README.md                         # install + rotation runbook
├── test/                                 # Phase 0 dev harness
│   ├── phase0_config_probe.sh            # token + endpoint + paging probes
│   ├── .env.example                      # collector creds template
│   └── README.md                         # runbook
├── milestone-rest-rework.md              # design rationale
├── milestone-rework-brief.md             # phased build sequence + decisions
├── milestone_*_{read,refresh,state}.{sh,py}
│                                         # legacy externals (until Phase 3
│                                         # cutover deletes them)
├── template_milestone_rs_extras.yaml     # legacy RS-extras template (same)
└── README.md                             # this file
```

## End state (after Phase 3 cutover)

- All Milestone data — inventory and live state — flows through one
  systemd service on the Zabbix proxy.
- No files under `/usr/lib/zabbix/externalscripts/milestone_*`.
- No cron entries running Milestone helpers.
- Three inventory trappers (`milestone.cameras.getall`,
  `milestone.groups.get`, `milestone.rs.extras.get`) and one heartbeat
  trapper (`milestone.collector.heartbeat`) carry everything.
- Surveillance NOC page opens a browser WS directly to the Gateway with
  a server-minted short-lived token (Phase 4) for sub-second state
  updates; the same 5-GUID subscription the collector uses.

## Legacy-externals section (kept for archival reference)

## Deploy

1. Copy the three scripts to the Zabbix server / proxy:

   ```
   sudo install -o zabbix -g zabbix -m 0750 \
       milestone_rs_state.py milestone_rs_refresh.sh milestone_rs_read.sh \
       /usr/lib/zabbix/externalscripts/
   ```

2. Create the snapshot + log directories:

   ```
   sudo install -d -o zabbix -g zabbix /var/lib/zabbix /var/log/zabbix
   ```

3. Add the cron entry (as the `zabbix` user — `crontab -u zabbix -e`):

   ```
   */15 * * * * /usr/lib/zabbix/externalscripts/milestone_rs_refresh.sh \
       <API-GATEWAY-HOST> <USER> '<PASSWORD>' --scheme https \
       >/dev/null 2>&1
   ```

   Add `--insecure` if the API Gateway uses a self-signed certificate.

4. In Zabbix, import `template_milestone_rs_extras.yaml`
   (Data collection → Templates → Import).

5. Link the new template to the same host that already has
   `Milestone XProtect by HTTP` linked. Macros are inherited from the
   base template — nothing else to configure.

6. Wait one cycle (≤ 15 min) for the cron to write the first snapshot,
   then look for `milestone_rs_read.sh[3600]` to start producing data
   and the two LLDs to discover items.

## How it differs from the existing groups script

The `milestone_groups_state.py` snapshot in the field today emits only
the `__array` form, with no top-level GUID keys. That breaks the base
template's per-group dependent items because their JSONPath
(`$["{#GRP.ID}"]`) resolves to nothing — every Sites-tab row was
falling through to the bare group GUID until the PHP back-fill landed
([ActionSurveillanceData.php](../../actions/ActionSurveillanceData.php) now reads `__array` directly to back-fill).

`milestone_rs_state.py` writes the snapshot with **both** shapes from
the start:

```json
{
  "__array": [ {"id":"...", ...}, ... ],
  "<rs-guid-1>": {"id":"...", ...},
  "<rs-guid-2>": {"id":"...", ...},
  "__storages": [ {"rsId":"...","id":"...", ...}, ... ]
}
```

so the template's per-RS dep items resolve cleanly and the dashboard
doesn't need a back-fill path for RS data.

Recommended follow-up: patch `milestone_groups_state.py` to do the same
(add a top-level GUID key for every group alongside `__array`). Once
that's done the PHP back-fill in `collectSiteItems()` will simply be
dormant; remove it if you want.

## Test plan

After deploy:

- [ ] `sudo -u zabbix /usr/lib/zabbix/externalscripts/milestone_rs_refresh.sh ...`
      exits 0 and produces `/var/lib/zabbix/milestone_rs_state.json`.
- [ ] `jq '.__count, .__total_storages' /var/lib/zabbix/milestone_rs_state.json`
      reports the expected RS and storage counts.
- [ ] `jq '.__array[0] | {id,displayName,state,cameraCount,hardwareCount,storageTotalBytes}'`
      shows non-empty values.
- [ ] In Zabbix, the master item `milestone_rs_read.sh[3600]` has a
      `lastvalue` and is not in the staleness trigger state.
- [ ] Both LLDs (`milestone.rs.extras.discovery`,
      `milestone.rs.storage.discovery`) have discovered one row per
      RS / per storage.
- [ ] On the Surveillance NOC dashboard, the Servers tab shows per-RS
      camera / hardware counts and the Sites tab's storage column shows
      a real percentage instead of `—`.

## Tuning

- **Cron cadence.** 15 min is the default. Drop to 5 min if you want
  fresher used-bytes / retention numbers; raise to 1 h on small sites
  to reduce API Gateway load.
- **Camera-count cost.** `collect_rs()` calls `/hardware/{id}/cameras`
  per hardware so the count is exact. At sites with thousands of
  hardware devices that's the dominant cost. If you'd rather skip it,
  comment out the `for hw in hw_arr` loop in
  [milestone_rs_state.py](milestone_rs_state.py) — `cameraCount` will
  stay 0 and the dashboard's RS-camera column will fall back to
  whatever the base template publishes.
- **API timeouts.** `--timeout 30` is per-request. Bump it via the
  cron entry if your API Gateway is slow to respond at peak.
