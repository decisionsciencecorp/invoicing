#!/usr/bin/env bash
# Local PHP + known admin for authenticated design smoke.
# Usage: bash tools/design-smoke/run-local-smoke.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SMOKE="$ROOT/tools/design-smoke"
TMP="${TMPDIR:-/tmp}/inv-design-smoke-$$"
mkdir -p "$TMP" "$SMOKE/out"
DB="$TMP/invoicing.db"
PORT="${PORT:-8765}"

export INVOICING_TEST=1
export DB_PATH="$DB"
export SITE_URL="http://127.0.0.1:${PORT}"
export INVOICING_INITIAL_ADMIN_PASSWORD=admin
export INVOICING_WEB_BASE=

# Bootstrap schema + admin user via a one-shot PHP require
php -r '
require "'"$ROOT"'/public/includes/config.php";
// config.php already loads database.php — do not re-require (redeclare fatal).
$db = getDbConnection();
initializeDatabase(true);
echo "db_ok admin_count=" . $db->querySingle("SELECT COUNT(*) FROM admin_users") . PHP_EOL;
'

php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >/tmp/inv-php-smoke.log 2>&1 &
PID=$!
trap 'kill '"$PID"' 2>/dev/null || true; rm -rf '"$TMP"'' EXIT
sleep 0.4

cd "$SMOKE"
BASE_URL="http://127.0.0.1:${PORT}" ADMIN_USER=admin ADMIN_PASS=admin node smoke.mjs

# Also rebuild fixture against local assets (no CDN)
cat > "$SMOKE/out/admin-fixture.html" <<HTML
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="http://127.0.0.1:${PORT}/assets/vendor/bootstrap/css/bootstrap.min.css?v=5.3.3">
<link rel="stylesheet" href="http://127.0.0.1:${PORT}/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.11.3">
<link rel="stylesheet" href="http://127.0.0.1:${PORT}/assets/css/invoicing.css?v=6">
</head>
<body class="inv-app">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark admin-nav">
  <div class="container-fluid px-3 px-lg-4">
    <a class="navbar-brand fw-semibold" href="#"><i class="bi bi-receipt"></i> Invoicing</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#n" aria-controls="n" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="n">
      <div class="d-flex flex-column flex-lg-row flex-wrap gap-2 ms-lg-auto inv-nav-cluster py-3 py-lg-0">
        <a class="btn btn-outline-light active" href="#">Dashboard</a>
        <a class="btn btn-outline-light" href="#">Companies</a>
        <a class="btn btn-outline-light" href="#">Time</a>
        <a class="btn btn-outline-light" href="#">Invoices</a>
        <button class="btn btn-outline-light dropdown-toggle" type="button">Settings</button>
        <a class="btn btn-outline-light" href="#">Help</a>
        <button class="btn btn-outline-light">Logout</button>
      </div>
    </div>
  </div>
</nav>
<div class="inv-shell">
  <nav class="tabbar"><a class="active" href="#">Publish</a><a href="#">List</a><a href="#">Unpaid / AR</a></nav>
  <div class="page-header"><div class="page-header__title"><h1>Invoices</h1><div class="subtitle">Hourly needs a Tasks doc</div></div>
  <div class="page-header__actions"><a class="btn btn-primary" href="#">Action</a><a class="btn btn-outline" href="#">Secondary</a></div></div>
  <div class="inv-kpi-row">
    <div class="inv-kpi"><div class="inv-kpi__label">Companies</div><div class="inv-kpi__value">3</div></div>
    <div class="inv-kpi"><div class="inv-kpi__label">Unpaid</div><div class="inv-kpi__value">2</div></div>
  </div>
  <div class="surface surface-pad">
    <p>Surface body text with <a href="#">a link</a> and <code>code</code>.</p>
    <label for="x">Engagement</label>
    <select id="x" class="form-select"><option>Select…</option></select>
    <label for="y">Amount</label>
    <input id="y" class="form-control" value="100.00">
    <table class="inv-table mt-3"><thead><tr><th>Month</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <tr><td>2026-07</td><td><span class="status-pill status-pill--paid">paid</span></td><td><button class="btn btn-outline btn-sm">Refresh</button></td></tr>
      <tr><td>2026-06</td><td><span class="status-pill status-pill--published">published</span></td><td><button class="btn btn-sm">Default btn</button></td></tr>
    </tbody></table>
  </div>
</div>
<script src="http://127.0.0.1:${PORT}/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=5.3.3"></script>
</body></html>
HTML

node --input-type=module <<'NODE'
import { chromium } from 'playwright';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const out = join(dirname(fileURLToPath(import.meta.url)), 'out');
const browser = await chromium.launch();
for (const [name, size] of [['mobile', {width:390,height:844}],['desktop',{width:1280,height:800}]]) {
  const page = await browser.newPage({ viewport: size });
  await page.goto('file://' + join(out, 'admin-fixture.html'), { waitUntil: 'networkidle' });
  await page.screenshot({ path: join(out, `admin-fixture-${name}.png`), fullPage: true });
  await page.close();
}
await browser.close();
console.log('fixture shots ok');
NODE
