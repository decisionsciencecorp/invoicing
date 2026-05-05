# Invoicing product — PRD v0.1 (draft)

**Product:** Internal / client-facing invoicing for **professional services** (not retail inventory).  
**Owner:** Mark Hopkins — Decision Science Corp.  
**Stack target:** PHP + SQLite on multihost, aligned with **Kitchen POS** patterns (see §5).  
**Payments:** **Square** — retainers as subscriptions; overage billing layered on top.  
**Default economics in this draft:** **USD $100/hour** technical consulting; **monthly retainers** with **overage hours** rolled into subsequent billing cycles.

---

## 1. Open decision items (need your call before build)

These block detailed schema and some Square flows. Answer inline or in Tasks comments.

| # | Topic | Question |
|---|--------|----------|
| **D1** | **Retainer shapes** | Fixed monthly dollar amount only, or also “N hours included per month” with the same hourly rate for overage? |
| **D2** | **Overage attachment** | Prefer **one consolidated monthly invoice** (retainer line + overage line) via **Invoices API**, or **Square Subscriptions** for the fixed retainer plus a **separate overage invoice** each cycle? |
| **D3** | **Overage carry-forward rule** | Confirm: “Hours over the included bucket in month M are billed in month M+1’s cycle” — bill **100% of overage hours × $100** on the **first invoice after month-end**, or allow **multi-month deferral** / **payment plans**? |
| **D4** | **Hour tracking source** | Are hours entered **only manually** in this app, or also imported (calendars, `.csv`, other tools)? |
| **D5** | **Clients vs Square Customers** | One **Square Customer** per billing entity (company), or per contact person? Multiple active engagements per customer? |
| **D6** | **Card on file** | Require **card on file** for subscriptions (Square charges automatically), or allow **invoice-with-link-only** for some clients? |
| **D7** | **Tax** | Do invoices need **sales tax / VAT** lines by jurisdiction, or always **tax-exempt / passthrough** consulting? |
| **D8** | **Multi-currency** | USD-only for v1? |
| **D9** | **Authentication** | Admin-only (you + staff), or **client portal** (login to see invoices / hours) in v1? |
| **D10** | **Square environment** | Sandbox-first until you approve production keys on `invoicing.decisionsciencecorp.com` config screen — confirm. |

---

## 2. Executive summary

Build a **DSC-standard PHP + SQLite** application that:

1. Manages **clients**, **engagements**, and **time entries** (consulting hours).
2. Defines **retainers** as **recurring revenue** — modeled with **Square Subscriptions** (Catalog subscription plan + plan variation + customer + card where applicable).
3. Computes **overage** (hours beyond included allowance, or all variable hours if there is no included bucket) each period and **charges** through Square using the **Invoices API** (and/or subscription-generated invoices — see D2).
4. Maintains **auditability** in SQLite: local truth for hours and billing period state; Square truth for money movement and invoice PDFs in Dashboard.

**Important Square constraint (from Square’s subscription billing docs):** subscription-generated invoices are **per billing period** and do **not** automatically show a running balance across periods. **Rolling overage into “the next cycle’s bill”** is **application logic**: we compute period totals locally, then create or adjust **invoice line items** Square-side (or publish one composite invoice) so the customer sees **retainer + carried overage** as required.

---

## 3. Goals and non-goals

### Goals

- **G1.** Selling **services** at a known hourly rate ($100/hr default); configurable per engagement.
- **G2.** **Recurring retainers** (subscriptions) through Square.
- **G3.** **Overage billing** for hours beyond the plan (or all non-retainer time), with **clear rules** for which month’s hours appear on which invoice.
- **G4.** **Operational parity** with Kitchen POS: same deployment class (multihost), **no nginx routing tricks**, secrets outside git, **API key** + **session admin** patterns.
- **G5.** **Webhook-driven reconciliation** where Square is asynchronous (invoice paid, subscription updated).

### Non-goals (v1)

- Physical catalog / inventory / DoorDash / Catalog MENU_CATEGORY (Kitchen POS retail scope).
- Full accounting GL — export-oriented OK later; not v1.

---

## 4. Square API research (concise)

**Workspace reference implementation:** `projects/kitchen-pos/public/includes/square.php` — **curl-based** JSON client to `connect.squareup.com` / `connect.squareupsandbox.com`, **no Square vendor SDK**. Credentials: env → SQLite `config` table → optional `.env` files; **same pattern** recommended for invoicing.

**SDK note:** Kitchen POS ships **KitchenPos** PHP/Python SDKs (`kitchen-pos/SDK/`) for **Kitchen POS’s own REST API**, not for Square’s REST API. For invoicing, mirror **`square_request()`** style or adopt Square’s official SDK later — **pattern parity** matters more than package choice for v1.

### APIs relevant to this product

| Square surface | Role |
|----------------|------|
| **Customers API** | Create/update **customer_id** for each billing entity; link to cards. |
| **Cards API** | Card on file for **automatic subscription** charging. |
| **Catalog API** | **Subscription plans & variations** (`CatalogSubscriptionPlan`, phases, cadence `MONTHLY`, anchor dates, optional proration flags). |
| **Subscriptions API** | Create/manage **subscription** to a plan variation; status, `invoice_ids`, pause/resume/cancel. |
| **Invoices API** | Create draft → publish invoices; **payment_requests**; scheduled delivery; webhooks `invoice.payment_made`, etc. |
| **Payments / Orders** | Secondary for v1 if we use Quick Pay links like catering — prefer **Invoices** + **Subscriptions** for clarity. |
| **Webhooks** | Verify HMAC (`x-square-hmacsha256-signature`); subscribe to subscription + invoice events. |

**Subscriptions billing (Square docs):** Billing **in advance** per cadence; **card on file** → charge; else **email with pay link**. **ACH** limitations apply (not available for stored bank-on-file in same way as cards for subscriptions). Each invoice is tied to a period; **track payment status** via webhooks or Payments API.

**Overage:** Implemented by app: either **additional invoice** with line items for “Consulting overage — January 2026 — 4.5 hrs @ $100”, or **single merged invoice** before charging (depends on D2).

---

## 5. Alignment with Kitchen POS patterns

Mirror these unless there is a strong reason not to:

| Area | Kitchen POS pattern | Apply to invoicing |
|------|---------------------|-------------------|
| **Layout** | `public/admin/` (session auth), `public/api/` thin wrappers + `handlers/`, `public/includes/` | Same. |
| **Database** | SQLite file under `db/`; `initializeDatabase()` migrations; singleton connection | Same. |
| **Config** | `config` KV table + `get_config` / `set_config`; Square keys in DB + optional env | Same for Square + app settings. |
| **Auth** | Admin session (`auth.php`, `login.php`); API `X-API-Key` (`functions.php`) | Same; optional future client portal. |
| **CSRF** | POST forms require CSRF token | Same for admin. |
| **Square HTTP** | `square_request()`, idempotency keys, `square_sync_log`-style logging | New `includes/square.php` or shared thin wrapper; **idempotent** invoice/subscription creates. |
| **Checkout** | Catering uses **Online Checkout payment links** for one-off take — invoicing may use **Invoices API** instead for formal statements | Different endpoint family; same **server-side only** secrets. |
| **Webhooks** | Dedicated route, signature verify, no API key on webhook | Same (`public/api/square-webhook.php` pattern). |
| **Docs** | `docs/` for API + database | Same. |
| **Tests** | PHPUnit integration tests; sandbox group for live HTTP | Same structure. |

**Reference files (Kitchen POS):**

- `docs/overview.md`, `docs/database.md`, `docs/api.md`
- `public/includes/square.php`, `public/includes/auth.php`, `public/includes/database.php`
- `public/admin/config.php` (Square admin UX baseline)

---

## 6. Core domain model (draft)

SQLite-first entities (names indicative):

- **clients** — legal name, billing email, Square `customer_id`, notes.
- **engagements** — FK client; hourly rate (default 10000 cents/hr); status; optional link to **subscription_id** (Square) and **plan_variation_id** catalog id.
- **time_entries** — engagement, date, hours (decimal or minutes), memo, **billing_period** bucket (e.g. `2026-04`), optional `invoiced_invoice_id` when settled.
- **billing_periods** — engagement, period start/end, **included hours** (if any), **hours_logged**, **overage_hours**, **amount_due_cents** snapshot.
- **square_links** — map local invoice draft ids ↔ Square `invoice_id` / `subscription_id` / idempotency keys.

All monetary amounts **integer cents** in DB.

---

## 7. Billing flow (conceptual)

1. **During month M:** user logs time; app rolls up **hours per engagement**.
2. **End of M (or start of M+1):** compute **overage** vs retainer allowance (D1, D3).
3. **Square:**  
   - **Retainer:** handled by **Subscriptions** (already invoiced/charged per Square schedule).  
   - **Overage:** **CreateInvoice** with line items → **PublishInvoice** (or attach to next composite invoice per D2).
4. **Webhook `invoice.payment_made`:** mark local rows paid; lock time entries for that period.

**Idempotency:** every Square `POST` that accepts `idempotency_key` must use a **deterministic key** per (engagement, billing period, invoice kind) to safe retries.

---

## 8. Phasing (implementation)

| Phase | Deliverable |
|-------|-------------|
| **P0** | SQLite schema + admin login + config page + Square “test connection” (Locations list). |
| **P1** | Customers CRUD + link Square customer; time entry UI + reports by period. |
| **P2** | Catalog plan variation for “Monthly retainer $X” + create subscription (sandbox). |
| **P3** | Overage calculation + Invoices API draft/publish + webhooks. |
| **P4** | Hardening, exports, production keys, Runbook. |

---

## 9. Risks

- **Square product eligibility:** Confirm Subscriptions + Invoices features for your Square **seller account** and region (US assumed).
- **ACH / wire:** If clients pay ACH, subscription auto-debit may be limited — affects D6.
- **Webhook reliability:** Same lesson as Kitchen POS — implement **polling fallback** for invoice/subscription state if webhooks lag.

---

## 10. References

- Square — [Subscriptions overview](https://developer.squareup.com/docs/subscriptions-api/manage-subscriptions), [Subscription billing & invoices](https://developer.squareup.com/docs/subscriptions-api/subscription-billing), [Invoices API](https://developer.squareup.com/docs/invoices-api/overview), [Catalog subscription plans](https://developer.squareup.com/docs/subscriptions-api/setup-plan).
- Internal — `projects/kitchen-pos/docs/square-integration-plan.md`, `public/includes/square.php`.

---

*Draft v0.1 — Otto / Decision Science Corp — 2026-05-05.*
