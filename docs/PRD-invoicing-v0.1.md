# Invoicing product — PRD v0.2

**Product:** Internal / client-facing invoicing for **professional services** (not retail inventory).  
**Owner:** Mark Hopkins — Decision Science Corp.  
**Stack target:** PHP + SQLite on multihost, aligned with **Kitchen POS** patterns (see §5).  
**Payments:** **Square** — retainers + combined monthly invoices (retainer + prior-period overage).

---

## 1. Product decisions (locked — 2026-05-05)

| ID | Decision |
|----|-----------|
| **D1 — Retainer shape** | Retainer is defined as a **fixed number of included hours × that engagement’s hourly rate**. Default rate **$100/hr**, but **hourly rate is per engagement / client** (may differ). Included hours are an integer (the “fixed number” bucket); overage uses the engagement rate. |
| **D2 — Billing shape** | **One combined monthly invoice per engagement:** **current-period retainer component + previous month’s overage** (when applicable), issued on the monthly cycle. |
| **D3 — Payment / collections** | **No payment plans.** Overage (and the monthly invoice) is due on the **next billing cycle**. Unpaid → **work stoppage** policy (enforce operationally; system should surface **paid vs overdue** clearly). |
| **D4 — Time tracking** | **Manual entry only** in v1 — no calendar/CSV import. |
| **D5 — Square Customer** | **One Square Customer per company** (billing entity) for now. |
| **D6 — How clients pay (see §1a)** | **Not yet choosing** “card on file only” vs “mix.” We will support **admin-generated invoice delivery + Square payment link** (Invoices API publish flow). **Optional:** card on file for hands-off autopay — product may offer later per client. |
| **D7 — Tax (see §1b)** | **Undetermined in-app until validated with a Texas tax advisor.** Implementation should allow **tax lines / exempt toggle per invoice or jurisdiction** when you get definitive guidance. |
| **D8 — Currency** | **USD only.** |
| **D9 — UX** | **Admin portal** (you/staff), with the ability to **generate/send the invoice and payment link** through Square (published invoice → customer pays via Square-hosted flow). |
| **D10 — Square environment** | **Sandbox only** until production credentials are blessed on the deployed host. |

---

## 1a. Illuminating D6 — card on file vs invoice link (Square)

Square supports two common patterns:

**A — Card on file (automatic charge)**  
- You store a **payment card on the Square Customer** (`Cards` API / Web Payments + customer linkage).  
- **Subscriptions API** can charge that card each billing cycle without the customer clicking a link.  
- Best when the client agrees to recurring autopay.

**B — Invoice + payment link (customer-initiated each time)**  
- You create/publish an **Invoice** (Invoices API). Square emails the customer (or you share the link).  
- The customer opens the invoice and pays — **no stored card required**.  
- Matches “send a bill monthly” and aligns with **combined invoice** (D2) if we assemble line items in one Square invoice.

**Your stated direction (D9):** prioritize **admin tooling to publish invoices and surface payment links**, which maps to **B** as the primary UX. We can still **attach** card on file later for clients who want autopay.

---

## 1b. D7 — Texas sales tax on consulting (research note, not legal advice)

**Disclaimer:** This is **not** tax or legal advice. Confirm with a **CPA or Texas sales-tax specialist** before enabling tax lines in production.

**General pattern (public summaries / practitioner guides):** Many **standalone professional/consulting services** in Texas are **not taxable** when they are purely advisory and do not constitute a enumerated taxable service (e.g., certain **data processing**, **information services**, taxable **software/SaaS** under specific tests).

**Why your situation needs a pro:** Texas has expanded/clarified rules around **data processing** and related digital services (including **2025** regulatory updates). If engagements blend **pure consulting** with **implementation, hosting, SaaS, or “data processing”** characteristics, **partial or full taxability** may apply.

**Product implication:** Ship **configurable tax**: ability to set **tax exempt**, **flat rate %**, or **per-line tax** per invoice/engagement once your advisor gives rules.

Sources for follow-up (your CPA can reconcile): Texas Comptroller materials on taxable services; recent coverage of **data processing** rule amendments (e.g. industry summaries in 2025).

---

## 2. Executive summary

Build a **DSC-standard PHP + SQLite** application that:

1. Manages **companies (clients)**, **engagements** (hourly rate, included hours, Square linkage), and **manual time entries**.
2. Bills **monthly** with **one Square invoice** combining **retainer (included hours × rate)** and **prior month overage hours × rate** when applicable.
3. Uses **Square** primarily via **Customers**, **Invoices API**, and **Subscriptions/plan** constructs only where they simplify recurring pricing — **combined invoice** requirement may mean **Invoices API–first** composition rather than separate subscription invoice + separate overage invoice (implementation detail in technical design).
4. Keeps **SQLite** as source of truth for hours and period boundaries; Square as source of truth for **payment status** and official invoice PDFs.

**Square constraint:** Rolling **overage into “next month’s bill”** is **application-side** math; Square displays whatever line items we publish on that cycle’s invoice.

---

## 3. Goals and non-goals

### Goals

- **G1.** Hourly consulting with **per-engagement rate** (default $100/hr).
- **G2.** **Included hours** retainer (hours × rate); **overage** on following invoice window per §1.
- **G3.** **Combined monthly invoice** (D2).
- **G4.** **Kitchen POS–parity** architecture (PHP/SQLite, admin + API key patterns).
- **G5.** **Webhook reconciliation** for invoice payment events.

### Non-goals (v1)

- Retail/inventory/catalog sync (Kitchen POS food scope).
- CSV/calendar import (D4).
- Multi-currency (D8).

---

## 4. Square API research (concise)

**Workspace reference:** `projects/kitchen-pos/public/includes/square.php` — curl JSON to Square `v2`, config precedence env → SQLite → `.env`.

**SDK:** Kitchen POS **`SDK/php`** is **KitchenPos API**, not Square’s REST SDK — invoicing should duplicate **`square_request()`** idioms.

### Surfaces

| Square surface | Role |
|----------------|------|
| **Customers API** | One record per **company** (D5). |
| **Invoices API** | Draft → publish **combined monthly invoice**; payment link / email; webhooks. |
| **Catalog / Subscriptions** | Optional: subscription plan for fixed recurring components — **or** generate recurring invoices from app logic if cleaner with combined line items. |
| **Cards API** | Optional future: card on file for autopay (§1a). |
| **Webhooks** | `invoice.payment_made`, subscription events if used — verify HMAC. |

---

## 5. Alignment with Kitchen POS patterns

Same as v0.1: `public/admin/`, `public/api/handlers/`, `includes/` for auth, CSRF, SQLite, `square_request` style, webhook endpoint without API key (signature only).

**References:** `kitchen-pos/docs/overview.md`, `database.md`, `public/includes/square.php`, `public/admin/config.php`.

---

## 6. Core domain model (draft)

- **clients** — company; Square `customer_id`; billing email.  
- **engagements** — FK client; **hourly_rate_cents**; **included_hours_per_month** (integer); Square linkage fields.  
- **time_entries** — manual; engagement; occurred date; hours; memo; billing period key.  
- **billing_period_rollups** — per engagement per month: hours, overage vs included.  
- **invoices (local)** — period covered; link to Square `invoice_id`; paid flag from webhooks.

Amounts in **integer cents**.

---

## 7. Billing flow (conceptual)

1. Log time manually during month **M**.  
2. At monthly issuance (aligned to your chosen anchor day):  
   - Compute **overage from M−1** vs that month’s included bucket.  
   - Build **one invoice** with lines e.g. **Retainer — month M**, **Overage — month M−1** (hours × rate).  
3. Publish via Square Invoices API; admin can **copy/share payment link**.  
4. If not paid by policy deadline → **work stoppage** (operational); UI shows overdue.

---

## 8. Phasing

| Phase | Deliverable |
|-------|-------------|
| **P0** | SQLite + admin auth + Square sandbox config + test connection. |
| **P1** | Companies + engagements + manual time + period rollup view. |
| **P2** | Combined invoice draft/publish (sandbox) + payment webhook. |
| **P3** | Tax toggles / line templates per advisor input; polish + prod cutover after blessing. |

---

## 9. Risks

- **Tax:** Until CPA confirms, default new invoices to **exempt** or **manual tax entry** only.  
- **Square:** Combined invoice + subscription pricing may require **design choice** (pure Invoices vs Subscription + invoice adjustment) — technical spike early in P2.

---

## 10. References

- Square — [Invoices API](https://developer.squareup.com/docs/invoices-api/overview), [Subscriptions](https://developer.squareup.com/docs/subscriptions-api/manage-subscriptions), [Customers](https://developer.squareup.com/docs/customers-api/what-it-does).  
- Internal — `projects/kitchen-pos/public/includes/square.php`.

---

## Revision history

| Version | Notes |
|---------|--------|
| v0.1 | Initial draft + open questions. |
| v0.2 | Decisions D1–D10 locked per Mark; §1a D6 explainer; §1b Texas tax research note; invoice-first emphasis. |

---

*v0.2 — Otto / Decision Science Corp — 2026-05-05.*
