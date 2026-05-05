# DSC Invoicing

PHP + SQLite app for **consulting retainers + overage invoicing** via **Square**. Follows [Kitchen POS](https://github.com/decisionsciencecorp/kitchen-pos)-style layout: `public/` docroot, session admin, curl-based Square client.

- **PRD:** [`docs/PRD-invoicing-v0.1.md`](docs/PRD-invoicing-v0.1.md) (v0.2 content)
- **Tasks (Sanctum):** project **Invoicing** on `tasks.decisionsciencecorp.com` — todos must be assigned to that project (no orphans).

## Requirements

- PHP 8.1+ with `sqlite3`, `curl`, `openssl`, `json`

## Deploy (multihost)

Web root = `public/`. Database file: `db/invoicing.db` (created on first request, writable by `www-data`).

Optional env:

- `SITE_URL` — base URL for cookie `secure` flag and HSTS (e.g. `https://invoicing.decisionsciencecorp.com`)
- `INVOICING_INITIAL_ADMIN_PASSWORD` — used **only** when the database has zero admin users at first migration. If unset, the default password is **`admin`** — **change immediately** after first login.

## Local smoke

```bash
cd public
php -S 127.0.0.1:8080
# open http://127.0.0.1:8080/admin/login.php
```

## Square (sandbox)

Admin → **Square config**: access token, environment `sandbox`, optional location ID. **Test connection** calls `GET /v2/locations`.
