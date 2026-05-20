#!/usr/bin/env python3
"""
Probe PacketFence's radius_audit_logs API to discover what this release accepts.

The dashboard's PFClient::authFailuresForNode has hit, in succession:
  - 403 on  GET  /api/v1/radius_audit_logs                (perms)
  - 403 on  POST /api/v1/radius_audit_logs/search         (perms)
  - 404 on  POST /api/v1/radius_audit_logs/search         (after perms granted)
  - 400 on  GET  /api/v1/radius_audit_logs                (fallback)

This script logs in, then walks a matrix of endpoint shapes and prints the
HTTP status + a snippet of the response for each so we can see which one
this PF instance actually serves. Stdlib-only.

Usage:
  PF_URL=https://pf.example:9443 PF_USER=… PF_PASS=… \
    python3 probe_pf_radius_audit_logs.py [--switch-id <id>] [--insecure]

  Or:
  python3 probe_pf_radius_audit_logs.py \
    --url https://pf.example:9443 --user … --pass … \
    [--switch-id <id>] [--insecure]
"""
from __future__ import annotations

import argparse
import json
import os
import ssl
import sys
import urllib.error
import urllib.request
from typing import Any


def make_opener(verify_ssl: bool) -> urllib.request.OpenerDirector:
    ctx = ssl.create_default_context()
    if not verify_ssl:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx))


def request(
    opener: urllib.request.OpenerDirector,
    method: str,
    url: str,
    body: Any = None,
    headers: dict | None = None,
) -> tuple[int, str]:
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        with opener.open(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace") if e.fp else ""
        return e.code, body
    except urllib.error.URLError as e:
        return 0, f"URLError: {e.reason}"


def login(opener, base: str, user: str, password: str) -> str:
    status, body = request(
        opener, "POST", f"{base}/api/v1/login",
        body={"username": user, "password": password},
    )
    if status >= 400:
        raise SystemExit(f"login failed: HTTP {status}: {body[:400]}")
    try:
        return json.loads(body)["token"]
    except Exception as e:
        raise SystemExit(f"login returned no token: {e}: {body[:400]}")


def show(label: str, status: int, body: str, max_body: int = 400) -> None:
    snippet = body.strip().replace("\n", " ")
    if len(snippet) > max_body:
        snippet = snippet[:max_body] + "…"
    marker = "ok " if 200 <= status < 300 else "ERR"
    print(f"  [{marker}] HTTP {status:>3}  {label}")
    if snippet:
        print(f"          → {snippet}")


def probe(opener, base: str, token: str, switch_id: str | None) -> None:
    auth = {"Authorization": token}

    # ─── 1. Bare GET listings ──────────────────────────────────────────────
    print("\n== GET listings ==")
    show(
        "GET /api/v1/radius_audit_logs (no params)",
        *request(opener, "GET", f"{base}/api/v1/radius_audit_logs", headers=auth),
    )
    show(
        "GET /api/v1/radius_audit_logs?limit=1",
        *request(opener, "GET", f"{base}/api/v1/radius_audit_logs?limit=1", headers=auth),
    )
    show(
        "GET /api/v1/radius_audit_logs?limit=1&fields=id,mac,auth_status,created_at",
        *request(
            opener, "GET",
            f"{base}/api/v1/radius_audit_logs?limit=1&fields=id,mac,auth_status,created_at",
            headers=auth,
        ),
    )

    # ─── 2. POST /search ───────────────────────────────────────────────────
    print("\n== POST /search (minimal valid body) ==")
    show(
        "POST /api/v1/radius_audit_logs/search (limit=1, sort, fields, no query)",
        *request(
            opener, "POST",
            f"{base}/api/v1/radius_audit_logs/search",
            body={
                "limit": 1,
                "sort": ["created_at DESC"],
                "fields": ["id", "mac", "auth_status", "created_at"],
            },
            headers=auth,
        ),
    )

    print("\n== POST /search (with query) ==")
    show(
        "POST .../search  query=auth_status equals reject",
        *request(
            opener, "POST",
            f"{base}/api/v1/radius_audit_logs/search",
            body={
                "limit": 1,
                "sort": ["created_at DESC"],
                "fields": ["id", "mac", "auth_status", "created_at"],
                "query": {"op": "equals", "field": "auth_status", "value": "reject"},
            },
            headers=auth,
        ),
    )
    show(
        "POST .../search  query=auth_status is reject  (operator 'is' instead of 'equals')",
        *request(
            opener, "POST",
            f"{base}/api/v1/radius_audit_logs/search",
            body={
                "limit": 1,
                "sort": ["created_at DESC"],
                "fields": ["id", "mac", "auth_status", "created_at"],
                "query": {"op": "is", "field": "auth_status", "value": "reject"},
            },
            headers=auth,
        ),
    )

    # ─── 3. Switch identifier variants ─────────────────────────────────────
    if switch_id:
        print(f"\n== POST /search filtered by various switch_* fields (value={switch_id!r}) ==")
        for field in ("switch_id", "switch_ip_address", "switch_mac"):
            show(
                f"POST .../search  query={field} equals {switch_id}",
                *request(
                    opener, "POST",
                    f"{base}/api/v1/radius_audit_logs/search",
                    body={
                        "limit": 1,
                        "sort": ["created_at DESC"],
                        "fields": ["id", "mac", field, "auth_status", "created_at"],
                        "query": {"op": "equals", "field": field, "value": switch_id},
                    },
                    headers=auth,
                ),
            )
            show(
                f"GET  .../radius_audit_logs?{field}={switch_id}&limit=1",
                *request(
                    opener, "GET",
                    f"{base}/api/v1/radius_audit_logs?{field}={switch_id}&limit=1",
                    headers=auth,
                ),
            )
    else:
        print("\n(skip switch-id probes — pass --switch-id <id> to enable)")

    # ─── 4. Singular path some PF versions use ─────────────────────────────
    print("\n== Singular path sanity checks ==")
    show(
        "GET  /api/v1/radius_audit_log (singular)",
        *request(opener, "GET", f"{base}/api/v1/radius_audit_log", headers=auth),
    )
    show(
        "POST /api/v1/radius_audit_log/search (singular)",
        *request(
            opener, "POST",
            f"{base}/api/v1/radius_audit_log/search",
            body={"limit": 1, "sort": ["created_at DESC"], "fields": ["id"]},
            headers=auth,
        ),
    )

    # ─── 5. Confirm a peer endpoint works (sanity) ─────────────────────────
    print("\n== Peer endpoint sanity ==")
    show(
        "GET  /api/v1/locationlogs?limit=1",
        *request(opener, "GET", f"{base}/api/v1/locationlogs?limit=1", headers=auth),
    )
    show(
        "POST /api/v1/locationlogs/search (limit=1)",
        *request(
            opener, "POST",
            f"{base}/api/v1/locationlogs/search",
            body={"limit": 1, "sort": ["start_time DESC"], "fields": ["mac"]},
            headers=auth,
        ),
    )


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--url",  default=os.environ.get("PF_URL"))
    p.add_argument("--user", default=os.environ.get("PF_USER"))
    p.add_argument("--pass", dest="password", default=os.environ.get("PF_PASS"))
    p.add_argument("--switch-id", default=os.environ.get("PF_SWITCH_ID"),
                   help="Value to filter switch_id / switch_ip_address / switch_mac on")
    p.add_argument("--insecure", action="store_true",
                   help="Skip TLS verification (PF often uses a self-signed cert)")
    args = p.parse_args()

    missing = [k for k in ("url", "user", "password") if not getattr(args, k)]
    if missing:
        p.error(f"missing required: {', '.join(missing)} (pass via flag or PF_URL/PF_USER/PF_PASS)")

    base = args.url.rstrip("/")
    opener = make_opener(verify_ssl=not args.insecure)

    print(f"→ logging in to {base} as {args.user}")
    token = login(opener, base, args.user, args.password)
    print(f"  got token (len={len(token)})")

    probe(opener, base, token, args.switch_id)
    return 0


if __name__ == "__main__":
    sys.exit(main())
