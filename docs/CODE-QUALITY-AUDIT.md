# Code quality audit — DSC Invoicing vs Tasks / CRM

**Date:** 2026-07-31 · **Branch:** `dev` · **Scope:** next-release rigor program (#2134 / #2127)

## Parity checklist

| Area | Tasks / CRM bar | Invoicing status |
|------|-----------------|------------------|
| Flat `/api/*.php` + handlers | Yes | Yes — CRUD + publish/refresh/attach/cancel/AR/audit/config/keys/users |
| `docs/api.md` | Yes | Updated with new routes |
| Python SDK | `tasks_sdk/` | `invoicing_sdk/` covering listed endpoints |
| SMCP plugin | `smcp_plugin/tasks/` | `smcp_plugin/invoicing/` |
| API-first admin ops | Strong | Expanded; remaining session-only: password change UI, Square secret write UI |
| Design tokens | CRM `--crm-*` | `--inv-*` in `public/assets/css/invoicing.css` |
| Bootstrap 5.3 | CRM | **5.3.3 self-hosted** under `public/assets/vendor/` + Icons 1.11 |
| Help system | CRM help nav | `admin/help.php` sectioned help |
| Audit / observability | Varies | `audit_log` + Settings → Audit log |
| Webhooks admin | — | Settings → Webhooks |
| Playwright design smoke | Pattern | `tools/design-smoke/` (+ authenticated local runner) |
| PHPUnit coverage | Present | `php tools/check_coverage.php` — **Lines ≥ 90%** |
| Schema migrate | Automatic | `initializeDatabase()` on login/API/public — idempotent ensures; optional `tools/migrate.php` |

## Follow-ups (not blockers for this pass)

1. Merge `dev` → `main` when Mark calls the release (Ada prod sync).  
2. Optional CI wiring for `check_coverage.php` / design-smoke on GitHub Actions.  
3. Session CSRF endpoints for mutating config secrets stay admin-UI (secrets must not be writable via API without explicit Mark OK).
