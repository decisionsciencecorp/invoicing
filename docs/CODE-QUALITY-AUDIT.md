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
| Design tokens | CRM `--crm-*` | `--inv-*` in `public/css/style.css` |
| Help system | CRM help nav | `admin/help.php` sectioned help |
| Audit / observability | Varies | `audit_log` + Settings → Audit log |
| Webhooks admin | — | Settings → Webhooks |
| Playwright design smoke | Pattern | `tools/design-smoke/smoke.mjs` |
| PHPUnit coverage | Present | Existing suite + new helpers; no composer gate yet |
| Bootstrap 5.3 | CRM | Not adopted — keep current dark admin shell; tokens only for now |

## Follow-ups (not blockers for this pass)

1. Optional Bootstrap/theme convergence if Mark wants visual match to CRM.
2. Coverage gate in CI once `composer.json` / phpunit.xml thresholds are agreed.
3. Session CSRF endpoints for mutating config secrets stay admin-UI (secrets must not be writable via API without explicit Mark OK).
