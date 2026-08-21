#!/usr/bin/env bash
# Verify DSC overlay invariants after a Sanctum promote.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0

check() {
  local desc="$1"
  shift
  if "$@"; then
    echo "OK  $desc"
  else
    echo "FAIL $desc" >&2
    fail=1
  fi
}

check "SITE_NAME is DSC Invoicing" \
  grep -q "define('SITE_NAME', 'DSC Invoicing')" "$ROOT/public/includes/config.php"
check "SESSION_NAME is dsc_invoicing_admin" \
  grep -q "define('SESSION_NAME', 'dsc_invoicing_admin')" "$ROOT/public/includes/config.php"
check "health service is dsc-invoicing" \
  grep -q "dsc-invoicing" "$ROOT/public/api/health.php"
check "DEPLOY-LANES present" test -f "$ROOT/docs/DEPLOY-LANES.md"
check "OVERLAY-INVENTORY present" test -f "$ROOT/docs/OVERLAY-INVENTORY.md"
check "PSF backfill tool present" test -f "$ROOT/tools/backfill-psf-invoice-docs.php"
check "app name helper present" \
  grep -q "function dsc_invoicing_app_name" "$ROOT/public/includes/config.php"
check "invoice brand helper present" \
  grep -q "function dsc_invoicing_invoice_brand" "$ROOT/public/includes/config.php"
check "PSF project default still 4 (overlay)" \
  grep -q "return 4;" "$ROOT/public/includes/tasks-dsc.php"

if [[ "$fail" -ne 0 ]]; then
  echo "Overlay check failed." >&2
  exit 1
fi
echo "Overlay check passed."
