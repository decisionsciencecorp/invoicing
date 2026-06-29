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

1. **Admin → Invoices** — pick engagement, anchor month, **Tasks document id**
2. Preview totals, then **Publish to Square + client page**
3. Share the **client page** link (canonical); it lists retainer/overage amounts, due dates, Square pay buttons, and the accounting markdown breakdown

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
