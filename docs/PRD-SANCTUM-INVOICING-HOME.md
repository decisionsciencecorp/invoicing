# PRD — Sanctum Invoicing home (DSC overlay)

**Owner:** Otto Vernal (with Mark)  
**Status:** Phase 0 → ready to execute  
**Date:** 2026-08-21  
**Tasks board:** Invoicing · `project_id` **2** · list **Sanctum Invoicing home** (#438) · Tasks Doc **#1158**  
**Repos:**
- Product (upstream): `sanctumos/sanctum-invoicing` (to be created)
- Overlay / Ada sync: `decisionsciencecorp/invoicing` (`dev` until Phase 5, then `main`)
**Hosts:**
- DEV: `https://dev.invoicing.decisionsciencecorp.com` (branch `dev`, Square Sandbox)
- PROD: `https://invoicing.decisionsciencecorp.com` (branch `main`, Square Production)  
**Pattern refs:** Kitchen POS Doc [#1156](https://tasks.decisionsciencecorp.com/admin/doc.php?id=1156) · CRM overlay Doc [#1155](https://tasks.decisionsciencecorp.com/admin/doc.php?id=1155) · PSF coupling Doc [#958](https://tasks.decisionsciencecorp.com/admin/doc.php?id=958)

---

## 1. Plain version

Sanctum should own **Invoicing** as a product — same story as Sanctum CRM / Sanctum Tasks. DSC keeps a thin overlay (brand, Square lanes, Tasks wiring, PSF ops tools) and Ada syncs that overlay to multihost. Prod never points `REPO` at Sanctum.

Work happens on **`dev`** + the DEV subdomain until Phase 5. Schema upgrades stay **idempotent** so promote to prod cannot wipe or corrupt live SQLite.

---

## 2. Goals + non-goals

### Goals

1. One feature brain: `sanctumos/sanctum-invoicing`.
2. DSC overlay stays thin; Ada sync stays on `decisionsciencecorp/invoicing`.
3. DEV lane fully provisioned (`sites.env` + green `sync.sh` + cron) before code risk.
4. Every schema change is double-run safe (`CREATE IF NOT EXISTS` / guarded `ADD COLUMN`).
5. Configurable app name + invoice brand; Sanctum defaults with no PSF fallback.

### Non-goals

- Flipping prod `REPO` to Sanctum.
- Multi-org SaaS tenancy in one DB (later).
- Rewriting Square Invoices API.
- Open-sourcing DSC client ledgers / PSF-only backfill tools.
- Experimenting schema or publish on prod before Phase 5.

---

## 3. Hard constraints

| Constraint | Rule |
|---|---|
| Branch | Push program work to **`dev`** only until Phase 5 |
| Host | Verify on **dev.invoicing…**; no Ada prod sync until ship |
| Schema | Idempotent ensures only; no drop/recreate with data; no install-wizard reseed on live DB |
| Promote | Sanctum → DSC promote mechanical; prod DB untouched by repo politics |

---

## 4. Target architecture

```text
sanctumos/sanctum-invoicing     decisionsciencecorp/invoicing
┌─ product core ─────────┐     ┌─ overlay + prod lane ──────────┐
│ billing, API, UI,      │────►│ DSC brand, Square lanes,       │
│ skins, install shell   │promote│ Tasks wiring, PSF ops tools,  │
│ AGPL / Sanctum defaults│     │ DEPLOY-LANES, live secrets     │
└────────────────────────┘     └──────────────┬─────────────────┘
                                              │ Ada: DEV first, then main
                                              ▼
                         DEV  →  https://dev.invoicing.decisionsciencecorp.com
                         PROD →  https://invoicing.decisionsciencecorp.com
```

---

## 5. Overlay vs product split (draft)

| Stay on DSC only | Must be Sanctum product |
|---|---|
| `SITE_NAME` / URL defaults for DSC hosts | Companies, engagements, time, drafts, outbound |
| Square lane secrets + remap sandbox tool | Public invoice page + admin surfaces |
| PSF backfill (`backfill-psf-invoice-docs.php`, admin button) | Schema ensures / migrate.php |
| Tasks host default `tasks.decisionsciencecorp.com` | Skin Lab, API, SDK, SMCP |
| `docs/DEPLOY-LANES.md`, multihost smoke | Neutral brand + config keys |
| Hardcoded footer link to decisionsciencecorp.com (until config) | Install / ConfigManager stub |

---

## 6. Phased plan

### Phase 0 — Audit (DEV first)

1. PSF/DSC hardwiring inventory  
2. Overlay vs product table (above)  
3. Branding / session / `SITE_NAME` / public lockup  
4. Square env + remap tools  
5. Boot path: always-on seed vs install wizard risk  
6. Deploy-lane truth (Ada DEV)  
7. Schema idempotency audit  
8. Publish findings here; close gate  

### Phase 1 — Stand up `sanctumos/sanctum-invoicing`

AGPL repo, Sanctum defaults, neutral session names, install stub, config-driven brand.

### Phase 2 — Reverse parity

Port generic surface; parameterize PSF defaults; keep `dsc_billing_*` symbols for now.

### Phase 3 — DSC overlay lane

Promote checklist + `tools/promote_from_sanctum.sh` + `tools/check_overlay.sh`.

### Phase 4 — Product config bar

App name + invoice brand settings; no PSF fallback in Sanctum defaults.

### Phase 5 — Prove + ship

Design-smoke on DEV; double-run migrate on prod-shaped DB; merge `dev`→`main`; Ada sync prod once.

---

## 7. Phase 0 findings (audit)

### 7.1 PSF / DSC hardwiring

| Location | Issue | Severity |
|---|---|---|
| `public/includes/config.php` | `SITE_NAME` = `DSC Invoicing` | Overlay |
| `public/includes/tasks-dsc.php` | `dsc_tasks_psf_project_id()` + PSF project default; Tasks base URL default | Productize via config; PSF id is overlay default |
| `public/admin/invoices.php` | “PSF” copy, backfill PSF button | Overlay UI / quarantine tool |
| `tools/backfill-psf-invoice-docs.php` | One-shot PSF hydrate | DSC-only |
| `public/includes/invoice-page-view.php` | Footer → decisionsciencecorp.com | Config brand |
| Unit tests | Hardcoded `invoicing.decisionsciencecorp.com` hosts | Lane-aware or config |
| Function prefix `dsc_*` | Naming debt | Defer rename |

### 7.2 Boot / seed risk

`initializeDatabase()` seeds `admin`/`admin` when `admin_users` empty. Safe for empty DEV; **must never** run an install wizard that recreates tables against prod. Sanctum install stub must refuse existing non-empty DB.

### 7.3 Schema idempotency

Current path is sound:

- `CREATE TABLE IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`
- Guarded `ADD COLUMN` via `PRAGMA table_info` in `dsc_invoicing_ensure_*`
- `tools/migrate.php` warms same ensures
- Seeds admin only when count = 0

**Landmine:** static `$created` / `$migrated` per process — fine for web; CLI must call once. Double-run of migrate is safe. Forbidden patterns not present in core schema today.

### 7.4 Deploy lanes

`docs/DEPLOY-LANES.md` already defines DEV vs PROD. Health: DEV and PROD both return HTTP 200 on `/api/health.php` (2026-08-21). Ada must still confirm `sites.env` + cron (not a frozen snapshot).

### 7.5 Square

Sandbox on DEV; production on PROD. Remap tool is lane ops (DSC). Product API client stays env-driven.

---

## 8. Acceptance (program)

- [ ] List #438 journaled with Program + Phase 0–5 + granular slices  
- [ ] Ada confirms DEV full provision on branch `dev`  
- [ ] `sanctumos/sanctum-invoicing` exists with AGPL + Sanctum brand defaults  
- [ ] Promote scripts land on DSC overlay repo  
- [ ] Schema double-run proven; no prod sync until Phase 5  
- [ ] Design-smoke on DEV after promote path  

---

## 9. Execution hygiene (Kitchen POS)

Each slice: tests (≥90% touched where applicable) → Tasks comment → close → commit/push **`dev`** → next. Do not invent product features only on the DSC tree after reverse-parity.
