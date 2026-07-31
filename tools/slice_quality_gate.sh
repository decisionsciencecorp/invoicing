#!/usr/bin/env bash
# Slice quality gate — PHPUnit coverage + optional Playwright visuals.
# Usage: tools/slice_quality_gate.sh [--with-visual]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
php tools/check_coverage.php --min=90
if [[ "${1:-}" == "--with-visual" ]]; then
  BASE_URL="${BASE_URL:-https://dev.invoicing.decisionsciencecorp.com}"
  (cd tools/design-smoke && BASE_URL="$BASE_URL" node smoke.mjs)
fi
echo "slice_quality_gate: OK"
