#!/usr/bin/env python3
"""Invoicing SMCP CLI — one subcommand per SDK method."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from invoicing_sdk import InvoicingClient, APIError, AuthenticationError, NotFoundError, ValidationError

BASE = os.environ.get(
    "INVOICING_API_BASE_URL",
    os.environ.get("INVOICING_DSC_BASE_URL", "https://invoicing.decisionsciencecorp.com"),
).rstrip("/")


def resolve_key(explicit: str | None) -> str:
    if explicit and explicit.strip():
        return explicit.strip()
    for var in ("INVOICING_SMCP_API_KEY", "INVOICING_DSC_OTTOVERNAL_API_KEY", "INVOICING_API_KEY"):
        val = os.environ.get(var, "").strip()
        if val:
            return val
    return ""


def main() -> int:
    p = argparse.ArgumentParser(description="DSC Invoicing SMCP plugin")
    p.add_argument("--api-key", default="")
    p.add_argument("--base-url", default=BASE)
    p.add_argument("--help-all", action="store_true")
    sub = p.add_subparsers(dest="cmd")

    cmds = [
        "health", "list_companies", "get_company", "create_company", "update_company", "delete_company",
        "list_engagements", "get_engagement", "create_engagement", "update_engagement", "delete_engagement",
        "list_time_entries", "get_time_entry", "create_time_entry", "update_time_entry", "delete_time_entry",
        "list_outbound_invoices", "get_outbound_invoice", "publish_combined_invoice",
        "refresh_outbound_invoice", "attach_tasks_document", "cancel_outbound_invoice",
        "list_unpaid_aging", "list_audit_log", "list_config", "list_api_keys", "list_admin_users",
    ]
    for name in cmds:
        sp = sub.add_parser(name.replace("_", "-"))
        sp.add_argument("--json", default="", help="JSON object of kwargs")
        if name.startswith("get_"):
            sp.add_argument("--id", type=int, default=0)

    args = p.parse_args()
    if args.help_all or not args.cmd:
        print(json.dumps({"commands": [c.replace("_", "-") for c in cmds]}, indent=2))
        return 0

    key = resolve_key(args.api_key)
    if not key and args.cmd != "health":
        print(json.dumps({"status": "error", "error": "API key required"}))
        return 2

    client = InvoicingClient(api_key=key or "unused", base_url=args.base_url)
    method = getattr(client, args.cmd.replace("-", "_"))
    kwargs = json.loads(args.json) if args.json else {}
    if hasattr(args, "id") and args.id:
        kwargs["id"] = args.id
    try:
        if args.cmd == "health":
            # health may not need key
            out = client.health()
        else:
            out = method(**kwargs)
        print(json.dumps({"status": "success", "result": out}, indent=2, default=str))
        return 0
    except (AuthenticationError, NotFoundError, ValidationError, APIError) as exc:
        print(json.dumps({"status": "error", "error": str(exc), "error_type": type(exc).__name__}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
