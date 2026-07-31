# Visual parity pack — next release (dev)

**Surface:** `https://dev.invoicing.decisionsciencecorp.com` · branch `dev` · tip includes self-hosted Bootstrap + `invoicing.css?v=4`.  
**Program:** [Doc #957](https://tasks.decisionsciencecorp.com/admin/doc.php?id=957) · [#2127](https://tasks.decisionsciencecorp.com/admin/view.php?id=2127).  
**How to refresh shots:** `bash tools/design-smoke/run-local-smoke.sh` or `BASE_URL=… ADMIN_USER=… ADMIN_PASS=… node tools/design-smoke/smoke.mjs`.

## Before (failure modes this release closed)

| Symptom | Cause |
|---------|--------|
| Nav pile / collapse never hides | Bootstrap loaded from jsDelivr; blocked CDN left `.collapse` unstyled |
| Light Bootstrap form chrome on dark pages | No `data-bs-theme="dark"`; bare `.btn` fought outline nav |
| Settings / Hours as top-level peers | Pre–Settings dropdown and pre–Time tabbar IA |
| Short “Recent” only | Pre–Publish / List / Unpaid tabs |

## After (current shell)

| Viewport | Routes captured |
|----------|-----------------|
| Desktop 1280×800 | Login, Dashboard, Invoices (Publish), Companies, Help |
| Mobile 390×844 | Same; hamburger collapses nav until toggled |

### Expected chrome

1. **Top nav** — Tasks-style dark bar, outline buttons, Settings dropdown, Logout.  
2. **Tabbars** — Invoices (Publish / List / Unpaid·AR); Time (entries / Hours); Settings (Password…Audit).  
3. **Surfaces** — `.inv-kpi`, `.surface` / `.info-box`, status pills, form-controls with strong borders.  
4. **Assets** — `/assets/vendor/bootstrap/*` and `/assets/vendor/bootstrap-icons/*` (no CDN).

Screenshots live under `tools/design-smoke/out/` (gitignored). Stakeholder copies are attached on task **#2127** when uploaded via Tasks `upload-attachment`.
