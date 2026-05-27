#!/usr/bin/env bash
# milestone_groups_read.sh
# ------------------------
# Reader that Zabbix's external check invokes for the camera-groups master
# item. It cats the JSON file produced by milestone_groups_refresh.sh, but
# FIRST strips the per-group cameraIds[] / hardwareIds[] arrays.
#
# Why strip them: at ~2,700 cameras the raw snapshot is ~400 KB, almost all
# of it those two ID arrays. A Zabbix text item value gets truncated well
# below that, which corrupts the JSON — the per-group dependent items
# (milestone.grp.name[<id>] etc.) and the PHP back-fill then fail to parse
# it and every Sites-tab row falls back to the bare group GUID. The group
# metadata the dashboard needs (name, path, parentGroupId, cameraCount,
# hardwareCount) is only ~4 KB once the ID arrays are gone, so it fits
# comfortably and the names resolve. Per-group camera/hardware membership
# is still available from the dedicated cameras snapshot, so nothing the
# dashboard reads here depends on the dropped arrays.
#
# Usage from Zabbix item key:
#   milestone_groups_read.sh[]
#   milestone_groups_read.sh[3600]    # custom max age
#
# Output file (must match milestone_groups_refresh.sh):
#   /var/lib/zabbix/milestone_groups_state.json

set -euo pipefail

OUT_FILE="/var/lib/zabbix/milestone_groups_state.json"
ERR_FILE="/var/lib/zabbix/milestone_groups_state.err"

# Tolerated age in seconds before we consider the snapshot stale.
# Default: 1 hour (4x the recommended 15-minute refresh cadence).
MAX_AGE="${1:-3600}"

if [[ ! -f "$OUT_FILE" ]]; then
    msg="snapshot file missing at $OUT_FILE; has milestone_groups_refresh.sh run yet?"
    printf '{"error":"no_snapshot","detail":"%s"}\n' "$msg"
    exit 0  # exit 0 so Zabbix stores the JSON, not a script-failure state
fi

# File age check.
NOW=$(date +%s)
MTIME=$(stat -c%Y "$OUT_FILE" 2>/dev/null || echo 0)
AGE=$(( NOW - MTIME ))
if [[ "$AGE" -gt "$MAX_AGE" ]]; then
    err_detail=""
    if [[ -f "$ERR_FILE" ]]; then
        err_detail=$(tr '\n' ' ' < "$ERR_FILE")
    fi
    printf '{"error":"stale","age_seconds":%d,"max_age_seconds":%d,"last_refresh_error":"%s"}\n' \
        "$AGE" "$MAX_AGE" "$err_detail"
    exit 0
fi

# Emit the snapshot with the heavy ID arrays stripped. The top-level GUID
# entries and the __array rows reference the same data in the file but are
# separate objects once parsed, so strip both. Fall back to the raw file
# only if python is unavailable (better stale-but-whole than nothing); the
# truncation risk returns in that case, so python3 should be present.
python3 - "$OUT_FILE" <<'PY' || cat "$OUT_FILE"
import json, sys

with open(sys.argv[1]) as f:
    data = json.load(f)

DROP = ("cameraIds", "hardwareIds")

def slim(obj):
    if isinstance(obj, dict):
        for k in DROP:
            obj.pop(k, None)

for row in data.get("__array", []):
    slim(row)
for key, val in data.items():
    if not key.startswith("__"):
        slim(val)

sys.stdout.write(json.dumps(data, separators=(",", ":")))
sys.stdout.write("\n")
PY
