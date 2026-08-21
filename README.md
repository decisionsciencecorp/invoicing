# DSC Invoicing

> ## STANDING ORDER — read this first
>
> **Sanctum builds Invoicing. This repo is a thin DSC overlay + Ada sync lane — not a second product.**
>
> | Lane | Repo | Role |
> |------|------|------|
> | **Product** | [`sanctumos/sanctum-invoicing`](https://github.com/sanctumos/sanctum-invoicing) | Feature brain (schema, billing, UI, API, skins) |
> | **Overlay** | `decisionsciencecorp/invoicing` (**this repo**) | DSC brand, Square lanes, Tasks/PSF wiring, deploy docs, org-only tools |
>
> 1. Invent product features **upstream** on Sanctum.  
> 2. Promote into this overlay: `tools/promote_from_sanctum.sh` then `tools/check_overlay.sh`.  
> 3. Ada syncs **this** repo (`dev` → DEV host, `main` → prod). **Never** flip multihost `REPO` to Sanctum.  
> 4. Schema upgrades stay **idempotent** so promote cannot corrupt prod SQLite.
>
> Full inventory: [`docs/OVERLAY-INVENTORY.md`](docs/OVERLAY-INVENTORY.md) · Program PRD: [`docs/PRD-SANCTUM-INVOICING-HOME.md`](docs/PRD-SANCTUM-INVOICING-HOME.md) · Tasks Doc **#1158** / standing Doc **#1159**.

PHP + SQLite app for **consulting retainers + overage invoicing** via **Square**. Follows [Kitchen POS](https://github.com/decisionsciencecorp/kitchen-pos)-style layout: `public/` docroot, session admin, curl-based Square client.

- **Billing rules PRD:** [`docs/PRD-invoicing-v0.1.md`](docs/PRD-invoicing-v0.1.md) (v0.2 content)
- **Deploy lanes:** [`docs/DEPLOY-LANES.md`](docs/DEPLOY-LANES.md) — DEV `dev.invoicing…` / PROD `invoicing…`
- **Tasks board:** project **Invoicing** (`project_id` **2**) — list **Sanctum Invoicing home** (#438)

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

## Tests (local only — never against production)

PHPUnit (pcov) with Square/Tasks mocked:

```bash
php tools/phpunit.phar -c phpunit.xml --coverage-text
```

Gate: **≥90%** line coverage on the instrumented tree (`public/includes` + `public/api/handlers`, excluding Parsedown, live HTTP transports, DB bootstrap, and session/csrf die paths covered by e2e).

Playwright e2e against a local `php -S` + temp SQLite DB:

```bash
npm --prefix tools/e2e install
npm --prefix tools/e2e test
```

## Square (sandbox)

Admin → **Square config**: access token, environment `sandbox`, optional location ID. **Test connection** calls `GET /v2/locations`.
