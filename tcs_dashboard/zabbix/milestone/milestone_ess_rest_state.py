#!/usr/bin/env python3
"""
milestone_ess_rest_state.py
---------------------------
Drop-in alternative to milestone_ess_state.py that fetches per-camera
state via the Milestone XProtect *Events* REST API instead of the ESS
WebSocket.

Why this exists
    The WebSocket ESS collector opens /api/ws/events/v1, subscribes to
    every event type on every camera, and calls getState — which at
    2500+ cameras takes 60-120 seconds per refresh and brings in the
    `websockets` package (with its periodic API renames; cf. the
    additional_headers vs. extra_headers bug we hit in production).

    The Events REST API provides the same information through three
    plain HTTP calls:
      1. GET /eventTypes        — to learn each type's stategroup
      2. GET /events?time=...   — to read events that happened in the
                                  last N hours (latest event per
                                  (camera, stategroup) IS the current
                                  state of that group for that camera)

    A typical 24h window for 2500 cameras returns a few thousand events
    total — pages of 2000 each, two or three pages — so the whole run
    finishes in seconds, with no websockets dependency.

    Output JSON is *identical* in shape to milestone_ess_state.py so the
    Zabbix templates and dashboard back-end keep reading the same fields:
        {"count": N,
         "cameras": {"<guid>": {"states": [...], "by_group": {...}}}}

    The --list-stategroups diagnostic mode is also preserved.

Usage:
    milestone_ess_rest_state.py <host> <username> <password>
                                [--scheme https] [--verify-tls]
                                [--timeout 60]
                                [--client-id GrantValidatorClient]
                                [--idp-path /IDP/connect/token]
                                [--api-base /api/rest/v1]
                                [--lookback-hours 24]
                                [--page-size 2000]
                                [--list-stategroups]

Exit codes:
    0  success (JSON on stdout)
    2  authentication failure
    3  HTTP / API error
    4  timeout
    1  other error

Requires: python3.8+ (urllib only; no aiohttp / websockets dependency).
"""
from __future__ import annotations

import argparse
import json
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from typing import Any


def _ssl_ctx(verify_tls: bool) -> ssl.SSLContext | None:
    ctx = ssl.create_default_context()
    if not verify_tls:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx


def get_token(base: str, idp_path: str, user: str, password: str,
              client_id: str, ctx: ssl.SSLContext | None,
              timeout: float) -> str:
    body = urllib.parse.urlencode({
        "grant_type": "password",
        "username": user,
        "password": password,
        "client_id": client_id,
    }).encode()
    req = urllib.request.Request(
        base + idp_path,
        data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded",
                 "Accept": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as r:
            payload = json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body_text = e.read().decode(errors="replace")[:500]
        raise RuntimeError(f"IDP HTTP {e.code}: {body_text}") from None
    tok = payload.get("access_token")
    if not tok:
        raise RuntimeError(f"IDP returned no access_token: {payload}")
    return tok


def api_get(base: str, token: str, path: str,
            ctx: ssl.SSLContext | None, timeout: float) -> dict[str, Any]:
    req = urllib.request.Request(
        base + path,
        headers={"Authorization": f"Bearer {token}",
                 "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body_text = e.read().decode(errors="replace")[:500]
        raise RuntimeError(f"GET {path} HTTP {e.code}: {body_text}") from None


# ---------------------------------------------------------------------------
# Event-type → state-group map
#
# Each /eventTypes record carries a nested stategroup object that pins
# the type to its state group. The WS protocol stamps stategroupid on
# every state row, so to match its output shape from REST events we
# need this lookup table.
# ---------------------------------------------------------------------------
def fetch_type_to_stategroup(
    base: str, token: str, ctx: ssl.SSLContext | None,
    timeout: float, api_base: str, page_size: int,
) -> dict[str, str]:
    """Return {event-type-guid: state-group-guid}. Empty if /eventTypes
    is unreachable or every record lacks a stategroup binding (which
    would mean none of those types are stateful).

    Pagination follows the same pattern as milestone_ess_resolve.py —
    walk pages of size 2000 until a short page comes back.
    """
    out: dict[str, str] = {}
    page = 0
    while True:
        url = f"{api_base}/eventTypes?page={page}&size={page_size}"
        try:
            payload = api_get(base, token, url, ctx, timeout)
        except RuntimeError as e:
            print(f"[stategroup-map] eventTypes page {page} failed: {e}",
                  file=sys.stderr)
            break
        arr = payload.get("array") or payload.get("data") or []
        if not arr:
            break
        for et in arr:
            if not isinstance(et, dict):
                continue
            tid = et.get("id")
            if not tid:
                continue
            # Field shape varies across API versions — tolerate flat and nested.
            sg = et.get("stategroup") or et.get("stateGroup") or {}
            if isinstance(sg, dict):
                sgid = sg.get("id", "")
            else:
                sgid = (et.get("stategroupId", "")
                        or et.get("stategroupid", ""))
            if sgid:
                out[tid] = sgid
        if len(arr) < page_size:
            break
        page += 1
    return out


# ---------------------------------------------------------------------------
# Events fetch
#
# Pull every camera-sourced event in the lookback window. The /events
# endpoint supports filtering by `time` (operators gt/lt) and ordering
# by `time`. We can't pre-filter to camera sources only (source.id only
# supports equals/oneOf, not startsWith/contains), so we filter
# client-side after each page.
# ---------------------------------------------------------------------------
def fetch_camera_events(
    base: str, token: str, ctx: ssl.SSLContext | None,
    timeout: float, api_base: str, lookback_hours: int,
    page_size: int,
) -> list[dict[str, Any]]:
    """Return all camera-sourced events within the lookback window.

    Events are returned newest-first (orderBy desc:'time') so that
    pivot_by_camera's "last-write-wins" within each (camera,stategroup)
    yields the latest known state with a single pass.
    """
    since = (datetime.now(timezone.utc) - timedelta(hours=lookback_hours)) \
        .strftime("%Y-%m-%dT%H:%M:%S.000Z")
    time_filter = urllib.parse.quote(f"gt:'{since}'", safe="")
    order_filter = urllib.parse.quote("desc:'time'", safe="")

    out: list[dict[str, Any]] = []
    page = 0
    while True:
        url = (f"{api_base}/events"
               f"?time={time_filter}"
               f"&orderBy={order_filter}"
               f"&page={page}&size={page_size}")
        try:
            payload = api_get(base, token, url, ctx, timeout)
        except RuntimeError as e:
            print(f"[events] page {page} failed: {e}", file=sys.stderr)
            break
        arr = payload.get("array") or payload.get("data") or []
        if not arr:
            break
        for ev in arr:
            if not isinstance(ev, dict):
                continue
            src = ev.get("source", "")
            # /events covers every source class (cameras, hardware,
            # microphones, ...). Keep only camera-sourced events so the
            # snapshot matches the WS collector's getState scope.
            if not (isinstance(src, str) and src.startswith("cameras/")):
                continue
            out.append(ev)
        if len(arr) < page_size:
            break
        page += 1
    return out


# ---------------------------------------------------------------------------
# Normalisation
#
# Same output shape as milestone_ess_state.pivot_by_camera — but the
# raw input is REST events, not WS getState rows, so each event carries
# (type, time, source) and we have to derive stategroupid from the
# type-to-stategroup lookup we built earlier.
#
# "Latest wins" inside each (camera, stategroup): since fetch_camera_events
# returns newest-first, the first event for a (camera, stategroup) pair
# IS the latest. Use setdefault to lock the first writer in.
# ---------------------------------------------------------------------------
def pivot_by_camera(
    events: list[dict[str, Any]],
    type_to_stategroup: dict[str, str],
) -> dict[str, dict]:
    out: dict[str, dict] = {}
    for ev in events:
        src = ev.get("source", "")
        if "/" not in src:
            continue
        resource_type, _, guid = src.partition("/")
        if resource_type.lower() != "cameras" or not guid:
            continue
        tid = ev.get("type") or ""
        sgid = type_to_stategroup.get(tid, "")

        # Synthesize the same per-state dict the WS protocol returns,
        # including the stategroupid we resolved from the type map.
        state_row = {
            "specVersion":   "1.0",
            "type":          tid,
            "source":        src,
            "time":          ev.get("time"),
            "stategroupid":  sgid,
        }
        entry = out.setdefault(guid, {"states": [], "by_group": {}})
        entry["states"].append(state_row)
        if sgid:
            entry["by_group"].setdefault(sgid, {
                "type": tid,
                "time": ev.get("time"),
            })
    return out


def list_stategroups(
    events: list[dict[str, Any]],
    type_to_stategroup: dict[str, str],
) -> dict[str, Any]:
    """Diagnostic: count cameras per (stategroupid, type) pair. Matches
    the WS collector's --list-stategroups output so downstream tools
    (milestone_ess_resolve.py) read either flavour the same way."""
    counter: dict[tuple[str, str], int] = defaultdict(int)
    for ev in events:
        src = ev.get("source", "")
        if not (isinstance(src, str) and src.startswith("cameras/")):
            continue
        tid = ev.get("type") or ""
        sgid = type_to_stategroup.get(tid, "")
        counter[(sgid, tid)] += 1
    rows = [
        {"stategroupid": sgid, "type": tid, "cameras": n}
        for (sgid, tid), n in sorted(counter.items(),
                                     key=lambda kv: (-kv[1], kv[0]))
    ]
    return {
        "total_states": len(events),
        "unique_pairs": len(rows),
        "pairs": rows,
    }


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument("host",
                    help="API Gateway host (no scheme), e.g. milestone.example.com")
    ap.add_argument("username")
    ap.add_argument("password")
    ap.add_argument("--scheme", default="https", choices=("http", "https"))
    ap.add_argument("--verify-tls", action="store_true",
                    help="Enforce TLS cert validation (default: off)")
    ap.add_argument("--timeout", type=float, default=60.0,
                    help="Per-operation timeout in seconds (default: 60). "
                         "REST /events pages return in seconds; the WS "
                         "collector's 180s default is way more headroom "
                         "than this path needs.")
    ap.add_argument("--client-id", default="GrantValidatorClient")
    ap.add_argument("--idp-path", default="/IDP/connect/token",
                    help="IDP token endpoint path. Default /IDP/connect/token; "
                         "older installs may use /API/IDP/connect/token.")
    ap.add_argument("--api-base", default="/api/rest/v1",
                    help="REST API base path (default /api/rest/v1)")
    ap.add_argument("--lookback-hours", type=int, default=24,
                    help="How far back to look for state-establishing "
                         "events (default: 24h). Any (camera, stategroup) "
                         "without an event in this window won't appear in "
                         "the snapshot — same behaviour as the WS collector "
                         "when a camera has been silent. Bump if you see "
                         "stable cameras dropping out of the snapshot.")
    ap.add_argument("--page-size", type=int, default=2000,
                    help="Page size for /events and /eventTypes "
                         "(spec max 2000; default 2000).")
    ap.add_argument("--list-stategroups", action="store_true",
                    help="Print unique (stategroupid,type) pairs for "
                         "one-time mapping of GUIDs to human meanings")
    args = ap.parse_args()

    base = f"{args.scheme}://{args.host}"
    ctx = _ssl_ctx(args.verify_tls)

    try:
        token = get_token(base, args.idp_path, args.username, args.password,
                          args.client_id, ctx, args.timeout)
    except RuntimeError as e:
        msg = str(e)
        if "IDP HTTP 400" in msg or "IDP HTTP 401" in msg \
                or "invalid_username_or_password" in msg \
                or "LockedOut" in msg:
            print(json.dumps({"error": "auth_failed", "detail": msg}),
                  file=sys.stderr)
            return 2
        print(json.dumps({"error": "protocol_error", "detail": msg}),
              file=sys.stderr)
        return 3
    except (urllib.error.URLError, TimeoutError) as e:
        print(json.dumps({"error": "http_error", "detail": repr(e)}),
              file=sys.stderr)
        return 3
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"error": "unexpected", "detail": repr(e)}),
              file=sys.stderr)
        return 1

    try:
        type_to_sg = fetch_type_to_stategroup(
            base, token, ctx, args.timeout, args.api_base, args.page_size)
        events = fetch_camera_events(
            base, token, ctx, args.timeout, args.api_base,
            args.lookback_hours, args.page_size)
    except (urllib.error.URLError, TimeoutError) as e:
        print(json.dumps({"error": "http_error", "detail": repr(e)}),
              file=sys.stderr)
        return 3
    except Exception as e:  # noqa: BLE001
        print(json.dumps({"error": "unexpected", "detail": repr(e)}),
              file=sys.stderr)
        return 1

    if args.list_stategroups:
        out = list_stategroups(events, type_to_sg)
    else:
        pivoted = pivot_by_camera(events, type_to_sg)
        out = {"count": len(pivoted), "cameras": pivoted}

    sys.stdout.write(json.dumps(out, separators=(",", ":")))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
