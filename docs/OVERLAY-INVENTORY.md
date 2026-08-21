# DSC Invoicing — overlay inventory (STANDING)

> **Sanctum = product. DSC = overlay.**  
> Do not invent product features only in this tree. Promote from [`sanctumos/sanctum-invoicing`](https://github.com/sanctumos/sanctum-invoicing), then Ada syncs this repo.

Companion to [PRD-SANCTUM-INVOICING-HOME.md](./PRD-SANCTUM-INVOICING-HOME.md) · Tasks Docs **#1158** (PRD) · **#1159** (standing order).

---

## Standing workflow

```text
sanctumos/sanctum-invoicing     decisionsciencecorp/invoicing (this repo)
┌─ product core ─────────┐     ┌─ DSC overlay ──────────────────┐
│ billing, API, UI,      │────►│ brand, Square lanes, Tasks/PSF │
│ skins, install shell   │promote│ DEPLOY-LANES, org-only tools  │
└────────────────────────┘     └──────────────┬─────────────────┘
                                              │ Ada sync
                                              ▼
                         DEV  →  https://dev.invoicing.decisionsciencecorp.com  (branch dev)
                         PROD →  https://invoicing.decisionsciencecorp.com      (branch main)
```

1. `tools/promote_from_sanctum.sh [path-to-sanctum-invoicing]`  
2. `tools/check_overlay.sh` (must pass)  
3. Push `dev` → Ada sync DEV → prove  
4. Merge `main` → Ada sync prod  

**Hard:** schema stays idempotent (`CREATE IF NOT EXISTS` / guarded `ADD COLUMN`). Never flip multihost `REPO` to Sanctum.

---

## DSC-only (do not upstream as product defaults)

| Path / concern | Notes |
|---|---|
| `SITE_NAME` = `DSC Invoicing` / `SESSION_NAME` = `dsc_invoicing_admin` | Overlay brand |
| Health service `dsc-invoicing` | Overlay |
| Square sandbox remap tool | Lane ops after prod→DEV DB copy |
| `tools/backfill-psf-invoice-docs.php` + admin PSF backfill | PSF client ops |
| Tasks base URL / PSF project default **4** | Overlay defaults; Sanctum defaults to unset (0) |
| `docs/DEPLOY-LANES.md` | DSC multihost lanes |
| Invoice brand defaults (Decision Science Corp URL) | Config-overridable |

## Product (must live on Sanctum)

Schema ensures, billing core, admin/public UI, API/SDK/SMCP, Skin Lab, drafts/outbound, install stub, generic `site_name` / `invoice_brand_*` config.
