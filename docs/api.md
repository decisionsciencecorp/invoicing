# DSC Invoicing — HTTP JSON API

**Audience:** integrations, automation, internal tools  
**Base URL:** `{origin}/api/` — one PHP script per endpoint (kitchen-pos style flat routes).  
**Auth:** Prefer **`X-API-Key`** or **`Authorization: Bearer <token>`**. Some callers also support `?api_key=` or JSON body field `api_key` on POST bodies (stripped before validation). Manage keys under **Admin → API keys**.

**Success:** JSON `{"success": true, …}` — additional fields vary by endpoint (often nested under logical keys inside `data` per handler).

**Errors:** JSON `{"success": false, "error": "<message>"}` with appropriate HTTP status (401/403/404/405/429/500 as implemented).

**Rate limits:** SQLite-backed fixed window per route + key + client IP (`checkRateLimit` in `public/includes/functions.php`). Exceeded → **429**.

**Health (unauthenticated):** `GET /api/health.php` — minimal liveness JSON.

---

## P3 research checkpoint (Kitchen POS pattern mirror)

Implementation follows the same **shape** as `kitchen-pos` JSON APIs:

- Thin `public/api/*.php` entrypoints (`require_once` config, `initializeDatabase()`, method guard, JSON body parse, delegate to handlers).
- Shared helpers in `public/includes/functions.php`: **`getApiKey()`** / **`dsc_invoicing_resolve_api_key()`**, **`validateApiKey()`**, **`checkRateLimit()`**, **`jsonSuccess()`** / **`jsonError()`**.
- Business logic concentrated in **`public/api/handlers/invoicing-crud-handlers.php`** for consistent auth + rate keys.
- Admin surface for keys: **`public/admin/api-keys.php`** (CSRF-protected create/delete), users: **`public/admin/users.php`**.

This file satisfies the Tasks “research checkpoint” + “`docs/api.md` reference” deliverables.

---

## Companies

| Method | Path | Notes |
|--------|------|--------|
| GET | `list-companies.php` | List |
| GET | `get-company.php?id=` | Single |
| POST | `create-company.php` | JSON body |
| POST | `update-company.php` | JSON body |
| POST | `delete-company.php` | JSON body |

## Engagements

| Method | Path | Notes |
|--------|------|--------|
| GET | `list-engagements.php` | Optional filters (see handler) |
| GET | `get-engagement.php?id=` | Single |
| POST | `create-engagement.php` | |
| POST | `update-engagement.php` | |
| POST | `delete-engagement.php` | |

## Time entries

| Method | Path | Notes |
|--------|------|--------|
| GET | `list-time-entries.php` | |
| GET | `get-time-entry.php?id=` | |
| POST | `create-time-entry.php` | |
| POST | `update-time-entry.php` | |
| POST | `delete-time-entry.php` | |

## Outbound invoices

| Method | Path | Notes |
|--------|------|--------|
| GET | `list-outbound-invoices.php` | |
| GET | `get-outbound-invoice.php?id=` | |
| POST | `publish-combined-invoice.php` | Admin-combined publish behavior (idempotent per product rules) |

## Integrations

| Method | Path | Notes |
|--------|------|--------|
| POST | `square-webhook.php` | Square webhook receiver (signature/validation per `includes/square.php`) — **not** generic CRUD |

---

## Contract details

Exact JSON field names and filter query parameters are defined by **`runInvoicingApi*`** handlers in `public/api/handlers/invoicing-crud-handlers.php`. When extending the API, add a row here and keep handler + admin UI in sync.

**Repository:** https://github.com/decisionsciencecorp/invoicing
