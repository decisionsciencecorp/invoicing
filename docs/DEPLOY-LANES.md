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

## Smoke (after sync)

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://dev.invoicing.decisionsciencecorp.com/admin/login.php
curl -sS -o /dev/null -w '%{http_code}\n' https://dev.invoicing.decisionsciencecorp.com/api/health.php
BASE_URL=https://dev.invoicing.decisionsciencecorp.com node tools/design-smoke/smoke.mjs
```
