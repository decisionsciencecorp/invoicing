#!/usr/bin/env bash
# Promote product tree from sanctumos/sanctum-invoicing into this DSC overlay.
# Does NOT touch multihost. Does NOT delete overlay-only paths.
#
# Usage:
#   tools/promote_from_sanctum.sh [/path/to/sanctum-invoicing]
# Default source: ../sanctum-invoicing (sibling clone) or SANCTUM_INVOICING_SRC.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-${SANCTUM_INVOICING_SRC:-$ROOT/../sanctum-invoicing}}"
if [[ ! -d "$SRC/public" ]]; then
  echo "Sanctum source not found: $SRC" >&2
  exit 1
fi

# Paths that stay DSC-owned (never clobber from Sanctum).
OVERLAY_KEEP=(
  docs/DEPLOY-LANES.md
  docs/OVERLAY-INVENTORY.md
  docs/PRD-SANCTUM-INVOICING-HOME.md
  tools/backfill-psf-invoice-docs.php
  tools/remap-sandbox-square-customers.php
  tools/promote_from_sanctum.sh
  tools/check_overlay.sh
)

echo "Promote from: $SRC"
echo "Into overlay: $ROOT"

# Product surfaces
rsync -a \
  --exclude='.git' \
  --exclude='logs/' \
  --exclude='db/*.db' \
  --exclude='docs/DEPLOY-LANES.md' \
  --exclude='docs/OVERLAY-INVENTORY.md' \
  --exclude='tools/backfill-psf-invoice-docs.php' \
  --exclude='tools/remap-sandbox-square-customers.php' \
  --exclude='tools/promote_from_sanctum.sh' \
  --exclude='tools/check_overlay.sh' \
  --exclude='README.md' \
  "$SRC/" "$ROOT/"

# Restore DSC brand defaults if Sanctum SITE_NAME leaked
if grep -q "define('SITE_NAME', 'Sanctum Invoicing')" "$ROOT/public/includes/config.php" 2>/dev/null; then
  sed -i "s/define('SITE_NAME', 'Sanctum Invoicing');/define('SITE_NAME', 'DSC Invoicing');/" \
    "$ROOT/public/includes/config.php"
fi
if grep -q "define('SESSION_NAME', 'sanctum_invoicing_admin')" "$ROOT/public/includes/config.php" 2>/dev/null; then
  sed -i "s/define('SESSION_NAME', 'sanctum_invoicing_admin');/define('SESSION_NAME', 'dsc_invoicing_admin');/" \
    "$ROOT/public/includes/config.php"
fi
if grep -q "sanctum-invoicing" "$ROOT/public/api/health.php" 2>/dev/null; then
  sed -i "s/'sanctum-invoicing'/'dsc-invoicing'/" "$ROOT/public/api/health.php"
fi

# Re-assert PSF overlay default if Sanctum zeroed it
if grep -q "return 0;" "$ROOT/public/includes/tasks-dsc.php" && grep -q "no PSF" "$ROOT/public/includes/tasks-dsc.php"; then
  python3 - "$ROOT/public/includes/tasks-dsc.php" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
t = p.read_text()
t = t.replace(
    "    // Sanctum product default: no PSF/board hardwiring.\n    return 0;\n",
    "    return 4;\n",
)
p.write_text(t)
print("restored PSF project default 4")
PY
fi

echo "Promote complete. Review diff, run tools/check_overlay.sh, then push branch dev."
echo "Kept overlay paths (do not delete): ${OVERLAY_KEEP[*]}"
