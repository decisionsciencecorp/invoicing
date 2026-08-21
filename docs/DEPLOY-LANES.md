> **STANDING:** This is the DSC overlay deploy map. Product home is Sanctum Invoicing — see README + `OVERLAY-INVENTORY.md`.

# Invoicing deploy lanes

| Lane | Host | Git branch | Square |
|------|------|------------|--------|
| **Dev** | `https://dev.invoicing.decisionsciencecorp.com` | `dev` | Sandbox |
| **Prod** | `https://invoicing.decisionsciencecorp.com` | `main` | Production |

## Rules

- Implement and verify on **dev** first.
- Ada owns multihost `sync.sh` / provision; Otto owns git push to the lane branch.
- After copying prod DB onto dev, remap Square customer IDs to sandbox before publishing test invoices.
- Do not re-publish a month that already has a paid Square invoice.

## Migrate + smoke (after sync)

**Schema:** App entrypoints run idempotent `initializeDatabase()` (CREATE + guarded ADD COLUMN). No manual migrate required after deploy. Optional: `php tools/migrate.php` to warm a vhost DB before traffic.

```bash
# On the host (or via deploy hook): idempotent schema ensure + column upgrades.
# Use the vhost DB — not the empty repo db/ stub under SRC_DIR.
cd /root/repos/dev.invoicing.decisionsciencecorp.com   # or prod clone
INVOICING_DB_PATH=/var/www/dev.invoicing.decisionsciencecorp.com/db/invoicing.db \
  php tools/migrate.php
# Prod example:
# INVOICING_DB_PATH=/var/www/invoicing.decisionsciencecorp.com/db/invoicing.db php tools/migrate.php

curl -sS -o /dev/null -w '%{http_code}\n' https://dev.invoicing.decisionsciencecorp.com/admin/login.php
curl -sS -o /dev/null -w '%{http_code}\n' https://dev.invoicing.decisionsciencecorp.com/api/health.php
php tools/check_coverage.php
BASE_URL=https://dev.invoicing.decisionsciencecorp.com node tools/design-smoke/smoke.mjs
# or: tools/slice_quality_gate.sh --with-visual
```
