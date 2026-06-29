# Client invoice breakdown (P4)

When you publish a monthly invoice, attach the **Tasks accounting document** (markdown). The app:

1. Fetches the document from `tasks.decisionsciencecorp.com` via API
2. Snapshots the markdown into SQLite (durable even if the Tasks doc later changes)
3. Creates **separate Square invoices** for retainer and overage (when overage &gt; 0)
4. Issues a **canonical client URL** on this host: `https://invoicing.decisionsciencecorp.com/invoice.php?t=<token>`

## Due dates (standard contract)

| Component | Due |
|-----------|-----|
| Monthly retainer ($500 default) | Upon receipt (publish day, UTC) |
| Prior-month overage | Net 30 from publish day |

## Admin flow

1. **Admin → Invoices** — pick engagement, anchor month, and a **PSF time log** from the dropdown (ProSpikeFlow Work → `client-facing` docs), or enter a Tasks document id manually
2. Preview totals, then **Publish to Square + client page**
3. Share the **client page** link (canonical); it lists retainer/overage amounts, due dates, Square pay buttons, and the accounting markdown breakdown

### Legacy invoices

Rows published before P4 may only have Square URLs. Use **Backfill PSF time logs onto prior invoices** on the invoices list (or run `php tools/backfill-psf-invoice-docs.php` on the server) to:

- Generate `public_token` + canonical client URLs
- Move legacy Square links into retainer/overage columns
- Attach PSF accounting docs: outbound **#3** → Tasks doc **621** (June retainer), **#4** → doc **332** (June overage)

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
