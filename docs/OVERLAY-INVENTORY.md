# DSC Invoicing — overlay inventory

Companion to [PRD-SANCTUM-INVOICING-HOME.md](./PRD-SANCTUM-INVOICING-HOME.md) / Tasks Doc **#1158**.

## DSC-only (do not upstream as product defaults)

| Path / concern | Notes |
|---|---|
| `SITE_NAME` / host URL defaults | DSC brand on overlay |
| Square sandbox remap tool | Lane ops after prod→DEV DB copy |
| `tools/backfill-psf-invoice-docs.php` + admin PSF backfill | PSF client ops |
| Tasks base URL default → tasks.decisionsciencecorp.com | Overlay default; Sanctum uses empty/config |
| `docs/DEPLOY-LANES.md` | DSC multihost lanes |
| Hardcoded footer decisionsciencecorp.com | Until brand config |

## Product (must live on Sanctum)

Schema ensures, billing core, admin/public UI, API/SDK/SMCP, Skin Lab, drafts/outbound.

## Promote

See `tools/promote_from_sanctum.sh` and `tools/check_overlay.sh` (Phase 3).
