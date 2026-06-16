# Phase 3 — cutover runbook

How to retire the eight Milestone externals (`milestone_*_{read,refresh,state}.{sh,py}`)
once the Phase 2 collector + Phase 5 heartbeat are healthy on dev. Reversible
up to step 5; destructive from step 6 onward.

Companion to `milestone-rework-brief.md` Phase 3 and `milestone-rest-rework.md`
§5. This document is the operator's checklist; the design rationale lives
there.

## Cutover map (what moves where)

| Live LLD / item                                  | Current master                       | Post-cutover master / type            |
|--------------------------------------------------|--------------------------------------|---------------------------------------|
| `milestone.cameras.discovery` (LLD)              | `milestone_cameras_read.sh[3600]`    | `milestone.cameras.getall` (TRAP)     |
| `milestone.cam.raw[{#CAM.ID}]`                   | `milestone_cameras_read.sh[3600]`    | `milestone.cameras.getall` (TRAP)     |
| `milestone.cam.ess.raw[{#CAM.ID}]`               | DEPENDENT on `milestone_ess_read.sh[]` | TRAP (collector pushes per-camera)  |
| `milestone.cam.ess.comm.{type,time}[{#CAM.ID}]`  | DEPENDENT on `milestone.cam.ess.raw` | unchanged (preprocessing reused)      |
| `milestone.cam.ess.rec.type[{#CAM.ID}]`          | DEPENDENT on `milestone.cam.ess.raw` | unchanged                             |
| `milestone.groups.discovery` (LLD)               | `milestone_groups_read.sh[3600]`     | `milestone.groups.get` (TRAP)         |
| `milestone.grp.raw[{#GRP.ID}]`                   | `milestone_groups_read.sh[3600]`     | `milestone.groups.get` (TRAP)         |
| `milestone.rs.extras.discovery` (LLD)            | `milestone_rs_read.sh[3600]`         | `milestone.rs.extras.get` (TRAP)      |
| `milestone.rs.storage.discovery` (LLD)           | `milestone_rs_read.sh[3600]`         | `milestone.rs.extras.get` (TRAP)      |
| Per-RS + per-storage dependents                  | DEPENDENT on `milestone_rs_read.sh`  | DEPENDENT on `milestone.rs.extras.get`|
| CALCULATED `milestone.cam.{status,alarm}`        | unchanged                            | unchanged                             |

Three EXTERNAL items go away entirely: `milestone_cameras_read.sh[3600]`,
`milestone_ess_read.sh[]`, `milestone_groups_read.sh[3600]`, plus the
`Milestone XProtect RS extras by HTTP` template's
`milestone_rs_read.sh[3600]`.

## 0. Pre-flight (dev) — must pass before touching production

All checks against the dev Zabbix + dev Milestone Gateway, with the **collector
running and the externals still running**:

- [ ] `systemctl status milestone-collector` is `active (running)` on the
      Zabbix proxy.
- [ ] `journalctl -u milestone-collector -n 50` shows recent `WS up` and
      `REST: pushed cameras (N)` lines with no repeating errors.
- [ ] **Heartbeat:** Latest Data shows `milestone.collector.heartbeat`
      updating once a minute; `milestone.collector.ws.connected` = 1; the
      `nodata(...,5m)` trigger is OK.
- [ ] **Three inventory trappers have values:**
      `milestone.cameras.getall`, `milestone.groups.get`, and
      `milestone.rs.extras.get` all show a recent timestamp and non-empty
      JSON. The shape is `{__count, __fetched_at, __array, "<guid>": …}`.
- [ ] **Per-camera state trappers are landing:** spot-check a handful of
      `milestone.cam.ess.raw[<guid>]` items — they should update when
      `getState` baselines run and when a camera toggles. The legacy
      `milestone.cam.ess.*` derived items still update too (off the
      external, unchanged).
- [ ] **Phase 4 browser bridge** opens (`[tcs-ws] WS up` in the DevTools
      console on the dev Surveillance NOC page) and tiles flip as cameras
      change state.
- [ ] **Phase 0 fact still holds:** if you've upgraded Milestone since
      Phase 0, re-run `tcs_dashboard/zabbix/milestone/test/phase0_config_probe.sh`
      and confirm no FACTS SUMMARY line regressed (especially Q-B paging
      and the IDP token path).

If anything is red, stop here and triage. Cutting over on top of a degraded
collector is what destroys data.

## 1. Parity soak (dev, ≥ 24 h)

The cameras and groups blobs are the load-bearing ones; the state path is
already trapper-fed and easier to spot-check live. Run both paths in
parallel and diff. Expected accepted differences are listed inline.

```bash
# On the Zabbix server / proxy that has API access. Adjust ZBX_URL + token.
ZBX_URL=https://zabbix.example.com/api_jsonrpc.php
TOKEN=<a zabbix API token with read access to the Milestone host>

# Helper: fetch the lastvalue of a trapper item, parsed as JSON.
zbx_lastvalue() {
    local key="$1"
    curl -sk -X POST "$ZBX_URL" -H 'Content-Type: application/json' \
        -d @- <<EOF | jq -r '.result[0].lastvalue'
{
  "jsonrpc":"2.0","method":"item.get","id":1,
  "params":{"output":["lastvalue"],"host":"<MILESTONE-ZBX-HOST>","search":{"key_":"$key"}},
  "auth":"$TOKEN"
}
EOF
}

# Cameras parity:
zbx_lastvalue 'milestone.cameras.getall' > /tmp/cameras.collector.json
sudo cat /var/lib/zabbix/milestone_cameras_state.json > /tmp/cameras.cron.json

# Counts must match. Field-by-field diff on the per-GUID records:
jq -S '.__count' /tmp/cameras.collector.json /tmp/cameras.cron.json
jq -S 'del(.__fetched_at) | to_entries | map(select(.key|test("^[0-9a-f-]{36}$")))
       | sort_by(.key)' /tmp/cameras.collector.json > /tmp/c.a
jq -S 'del(.__fetched_at) | to_entries | map(select(.key|test("^[0-9a-f-]{36}$")))
       | sort_by(.key)' /tmp/cameras.cron.json     > /tmp/c.b
diff /tmp/c.a /tmp/c.b | head -200
```

**Expected, accepted differences:**

- `mac` — the collector path leaves this `""` (Phase 0 finding: not exposed
  in the bulk API on this Gateway). The cron python's `--includeChildren=settings`
  fan-out used to fill it for some hardware. Impact: XIQ MAC correlation
  degrades for any host that relied on the Milestone MAC instead of the
  ARP-derived one. Verify the XIQ side still resolves; if not, escalate
  before cutover.
- `__fetched_at` — different timestamps, obviously.
- `relations.parent` — both should carry the same hardware GUID, but field
  ordering may differ (use `jq -S` everywhere to canonicalise).

**Unexpected differences** — STOP and triage:

- Any `recordingServerId` that differs for the same camera GUID.
- Camera count delta > 1% (a few cameras may legitimately drift across the
  diff window; > 1% means the parent-resolution join is broken somewhere).
- A camera present in one and missing from the other.

Repeat for groups (`milestone.groups.get` vs `milestone_groups_state.json`):
counts must match per group; `cameraCount` and `hardwareCount` should agree
to within a one-tick window.

Repeat for RS extras: storage totals, used, retention rollups should match
to within the collector's 15 min cadence vs cron's whatever-it-was. The
new `state` field is collector-only — that's expected.

**Acceptance:** ≥ 24 h soak with no unexpected differences and at least one
camera-state toggle observed propagating through both paths.

## 2. Operator window + rollback rehearsal

- [ ] Maintenance window scheduled long enough for steps 3–5 plus a
      verification pass (60–90 minutes is typical).
- [ ] **Snapshot the live template export** before any change — File →
      Templates → "Milestone XProtect by HTTP" + "RS extras by HTTP" →
      Export. Save with today's date. This is the rollback artefact.
- [ ] **Snapshot the live cron** with `sudo crontab -u zabbix -l > /tmp/zabbix.crontab.bak`.
- [ ] Rehearse `git revert` on this branch in a scratch worktree so the
      "restore the externals" path is one command in step 7's rollback.

## 3. Cutover — template edit (dev first, then prod)

Do this on dev, verify, then mirror on prod. The template lives in this repo
at `tcs_dashboard/zabbix/milestone/templates/milestone_by_http_api.yaml`;
edit-import is the canonical path, *not* the Zabbix UI's item editor (because
the next reimport would clobber UI-side changes).

**3a. Edit the template** (single PR, single import per environment):

For each row in the cutover map above —

1. Find the EXTERNAL item (search for `key: 'milestone_*_read.sh`).
2. Either **delete it** outright (preferred — orphans don't help) or change
   `type: EXTERNAL` → `type: TRAP` and rename the key to the collector key.
   Deleting is cleaner; the references below will point at the existing
   TRAP item.
3. Find every dependent / LLD that has `master_item.key: 'milestone_*_read.sh...'`
   and replace it with the collector trapper key (see map).
4. For `milestone.cam.ess.raw[{#CAM.ID}]`: change `type: DEPENDENT` to
   `type: TRAP`, **remove** the `master_item:` block, and **remove** the
   JAVASCRIPT preprocessing (the collector already pushes the per-camera
   record). Keep its description; update it to reflect the new source.
   The `…comm.type/.time/.rec.type` items below it stay DEPENDENT on
   `milestone.cam.ess.raw[…]` with their existing JS preprocessing —
   that's the whole reason we kept the per-camera key stable.
5. For the RS-extras template's LLDs (`milestone.rs.extras.discovery`,
   `milestone.rs.storage.discovery`): change their `master_item.key` to
   `milestone.rs.extras.get`. Same for the per-RS / per-storage dependents.
6. Delete the triggers that fire on stale-snapshot patterns
   (`find(...,"regexp","\"error\":\"(stale|no_snapshot)\"")=1`) — those
   alerted on the cron's `milestone_*_state.err` files, which no longer exist.

**3b. Validate before import:**

```bash
python3 -c "import yaml; yaml.safe_load(open('tcs_dashboard/zabbix/milestone/templates/milestone_by_http_api.yaml'))"
```

**3c. Import (dev):** Templates → Import → upload the edited YAML →
Update existing: items, triggers, discovery, value mappings → **Don't**
check "Delete missing" on the first pass; do a dry diff first. On second
pass, "Delete missing" is what removes the old EXTERNAL items if you
chose to delete-via-import rather than edit-in-place.

## 4. Verify (dev)

Within 10–15 minutes of the import:

- [ ] `milestone.cameras.discovery` LLD shows the same camera count as
      before the cutover (compare to the snapshot taken in step 2).
- [ ] `milestone.cam.raw[<guid>]` for three spot-check cameras: same
      `address`, `hardwareModel`, `recordingServerId` as pre-cutover.
- [ ] `milestone.cam.ess.comm.type[<guid>]` updates within 60 s of a
      manual camera toggle (or wait for an organic event).
- [ ] `milestone.grp.cam.count[<guid>]` for the largest group matches the
      pre-cutover value within ± 2 cameras.
- [ ] RS-extras: per-RS `state` shows `running` for everything that
      actually is; storage totals match within 1–2%.
- [ ] **Surveillance NOC page** (Phase 4 browser bridge) still renders;
      DevTools console shows `[tcs-ws] WS up` and no new warnings.
- [ ] **All triggers**: nothing newly firing that wasn't firing before.

If any check fails, **roll back** (step 7) before going to prod.

## 5. Stop the cron (but keep the files)

On the Zabbix server / proxy:

```bash
# Comment out the Milestone lines in the zabbix user's crontab; don't delete
# yet -- we'll restore from this if we have to roll back.
sudo crontab -u zabbix -e
#   prefix every milestone_* line with '#'

# Stop any in-flight external refresh:
sudo pkill -f 'milestone_(cameras|groups|rs|ess)_(refresh|state)' || true
```

The snapshot files (`/var/lib/zabbix/milestone_*_state.json`) stay in place;
they're stale now but innocuous. The external scripts themselves stay in
`/usr/lib/zabbix/externalscripts/milestone_*` for the same reason.

## 6. Production cutover

Repeat steps 3 and 4 against prod, in the agreed maintenance window. Step 5's
cron stop happens on the prod proxy too, against the prod cron. Keep dev's
collector running through the prod cutover — if prod hits a problem the
dev environment is your reference for "what healthy looks like".

## 7. Decommission sweep (≥ 7 days after step 6 with no incidents)

Destructive — do not run if any of the cutover validations from step 4 are
still showing yellow.

```bash
# On the Zabbix server / proxy:
sudo crontab -u zabbix -e
#   delete the commented-out milestone_* lines entirely

# Delete the external snapshots + lock + log files:
sudo rm -f /var/lib/zabbix/milestone_*_state.json \
           /var/lib/zabbix/milestone_*_state.err \
           /var/lib/zabbix/milestone_*_state.lock \
           /var/log/zabbix/milestone_*_state.log

# Delete the external script files:
sudo rm -f /usr/lib/zabbix/externalscripts/milestone_cameras_read.sh \
           /usr/lib/zabbix/externalscripts/milestone_cameras_refresh.sh \
           /usr/lib/zabbix/externalscripts/milestone_cameras_state.py \
           /usr/lib/zabbix/externalscripts/milestone_groups_read.sh \
           /usr/lib/zabbix/externalscripts/milestone_groups_refresh.sh \
           /usr/lib/zabbix/externalscripts/milestone_groups_state.py \
           /usr/lib/zabbix/externalscripts/milestone_rs_read.sh \
           /usr/lib/zabbix/externalscripts/milestone_rs_refresh.sh \
           /usr/lib/zabbix/externalscripts/milestone_rs_state.py \
           /usr/lib/zabbix/externalscripts/milestone_ess_read.sh \
           /usr/lib/zabbix/externalscripts/milestone_ess_refresh.sh \
           /usr/lib/zabbix/externalscripts/milestone_ess_state.py \
           /usr/lib/zabbix/externalscripts/milestone_ess_lookup.py \
           /usr/lib/zabbix/externalscripts/milestone_ess_resolve.py
```

Note: at least one of these (`milestone_groups_state.py`) was deployed
without ever landing in git. Run the `rm`s by name as above rather than
globbing on `milestone_*` so a future unrelated script with that prefix
isn't caught.

In the repo, on the Phase 3 cutover commit:

```bash
git rm tcs_dashboard/zabbix/milestone/milestone_cameras_*.{sh,py} \
       tcs_dashboard/zabbix/milestone/milestone_groups_*.{sh,py} \
       tcs_dashboard/zabbix/milestone/milestone_rs_*.{sh,py} \
       tcs_dashboard/zabbix/milestone/milestone_ess_*.{sh,py} \
       tcs_dashboard/zabbix/milestone/template_milestone_rs_extras.yaml
```

Tag the commit before merging so rollback can checkout that tag:

```bash
git tag -a milestone-pre-decommission -m "last commit with Milestone externals present"
git push origin milestone-pre-decommission
```

Update `tcs_dashboard/zabbix/milestone/README.md` — remove the "Legacy
externals section (kept for archival reference)" block.

## 8. Verify zero references

After the decommission sweep:

```bash
# In the repo:
git grep -nE 'milestone_(cameras|groups|rs|ess)_(read|refresh|state|lookup|resolve)' \
    tcs_dashboard/ \
  | grep -v '^docs\|milestone-rest-rework\.md\|milestone-rework-brief\.md\|cutover\.md'
# Expect: no matches outside docs.

# On the proxy:
ls /usr/lib/zabbix/externalscripts/milestone_* 2>/dev/null \
  && echo "FAIL: leftover externals" \
  || echo "ok: no externals"
sudo crontab -u zabbix -l | grep milestone || echo "ok: no cron"
```

## Rollback

**Up to step 5 (cron stop):** revert the template import. Templates →
Import → upload the snapshot YAML from step 2 → Update existing →
Delete missing for items/triggers/LLD. Re-enable the cron lines.
Collector keeps running in parallel — no harm.

**Steps 6–7 (cron entries / files deleted, prod cutover):** `git revert`
the cutover commit(s), redeploy the scripts to `/usr/lib/zabbix/externalscripts/`,
restore the cron from `/tmp/zabbix.crontab.bak`, reimport the
pre-cutover template snapshot. The cron snapshots will repopulate
`/var/lib/zabbix/milestone_*_state.json` on their first tick (15 min
for cameras/groups, 15 min for RS, 24 h for ESS — wait for them before
expecting the LLDs to repopulate).

Either rollback path: the collector keeps running. It does no harm with
the trappers detached from the LLDs; its heartbeat trigger still alerts
on the collector itself, which is what you want during a botched cutover.

## What "done" looks like

- Template has no `key: 'milestone_*_read.sh*'` references.
- No `/usr/lib/zabbix/externalscripts/milestone_*` files.
- No `crontab -u zabbix` lines mentioning milestone.
- `git grep` returns no script references outside the design docs.
- `milestone.collector.heartbeat` updating once a minute.
- Surveillance NOC tiles update sub-second on camera state changes
  (Phase 4 path) AND Latest Data on the Milestone Zabbix host shows
  `milestone.cam.ess.comm.type[…]` updating from the collector (Phase 2
  path) — both paths agree.
