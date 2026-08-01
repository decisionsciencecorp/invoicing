# Client invoice breakdown (P4)

When you publish a monthly invoice, attach the **Tasks accounting document** (markdown). The app:

1. Fetches the document from `tasks.decisionsciencecorp.com` via API
2. Snapshots the markdown into SQLite (durable even if the Tasks doc later changes)
3. Creates **separate Square invoices** for retainer and overage (when overage &gt; 0)
4. Issues a **canonical client URL** on this host: `https://invoicing.decisionsciencecorp.com/invoice.php?t=<token>`

## Due dates (standard contract)

| Component | Due |
|-----------|-----|
| Monthly retainer (hourly mode) | Upon receipt (publish day, UTC) |
| Prior-month overage (hourly mode) | Net 30 from publish day |
| Flat / tier program fee (`billing_mode=flat_tier`) | Net 30 from publish day (`fee_due_date`) |

## Flat / tier publish

1. Engagement must be `flat_tier` with Tier 1 / Tier 2 amounts set
2. Admin → Invoices — pick engagement, anchor month, **tier** (default Tier 1)
3. Tasks accounting document is **optional at publish**, but **required for a finished client page**: attach a real breakdown in the [Doc #964](https://tasks.decisionsciencecorp.com/admin/doc.php?id=964) format (exemplar [Doc #963](https://tasks.decisionsciencecorp.com/admin/doc.php?id=963) — AcquireROI July 2026). Do **not** leave Square restore / ops notes as `accounting_markdown`.
4. Client page shows a single **program fee** card (not retainer/overage split) on a **DSC-branded** public shell (not admin Appearance skins)
5. Share the permanent `invoice.php?t=` URL; unpaid months show a pay button on that same page

## Admin flow

1. **Admin → Invoices** — pick engagement, anchor month, and a **PSF time log** from the dropdown (ProSpikeFlow Work → `client-facing` docs), or enter a Tasks document id manually
2. Preview totals, then **Publish to Square + client page**
3. Share the **client page** link (canonical); it lists retainer/overage amounts, due dates, Square pay buttons, and the accounting markdown breakdown

### Legacy invoices

Rows published before P4 may only have Square URLs. Use **Backfill PSF time logs onto prior invoices** on the invoices list (or run `php tools/backfill-psf-invoice-docs.php` on the server) to:

- Generate `public_token` + canonical client URLs
- Move legacy Square links into retainer/overage columns
- Attach PSF accounting docs: outbound **#3** and **#4** → Tasks doc **332** (per PSF invoice tasks #881 / #882)

May 2025 retainer-only row (**#1**) gets tokens/URLs only — no matching time log on the PSF board yet.

## Configuration

**Admin → Square configuration → Tasks API** (or server env):

- `TASKS_DSC_BASE_URL`
- `TASKS_DSC_OTTOVERNAL_API_KEY` (or `tasks_dsc_api_key` in config table)

## API

`POST /api/publish-combined-invoice.php` JSON body now requires `tasks_document_id` in addition to `engagement_id` and `anchor_month`.

## Tests

```bash
cd projects/invoicing
# Unit only (no Square network):
./vendor/bin/phpunit --testsuite Unit

# Include sandbox Square invoice creation (needs sandbox token):
./vendor/bin/phpunit --group square-sandbox
```
