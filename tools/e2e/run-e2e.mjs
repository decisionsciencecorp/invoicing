/**
 * Local Playwright smoke — never hits production.
 * npm --prefix tools/e2e install && npm --prefix tools/e2e test
 */
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../..');
const publicDir = path.join(root, 'public');
const dbPath = path.join(os.tmpdir(), `dsc_invoicing_e2e_${process.pid}.db`);

function freePort() {
  return new Promise((resolve, reject) => {
    const s = createServer();
    s.listen(0, '127.0.0.1', () => {
      const addr = s.address();
      const p = typeof addr === 'object' && addr ? addr.port : 0;
      s.close(() => resolve(p));
    });
    s.on('error', reject);
  });
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

const port = await freePort();
const base = `http://127.0.0.1:${port}`;
fs.rmSync(dbPath, { force: true });

const phpEnv = {
  ...process.env,
  DB_PATH: dbPath,
  SITE_URL: base,
  INVOICING_TEST: '1',
  INVOICING_INITIAL_ADMIN_PASSWORD: 'admin',
  INVOICING_SQUARE_SKIP_ENV_FILE: '1',
  INVOICING_SQUARE_MOCK: '1',
  INVOICING_TASKS_MOCK: '1',
  SQUARE_ACCESS_TOKEN: 'e2e-token',
  SQUARE_LOCATION_ID: 'LOC_E2E',
  SQUARE_ENVIRONMENT: 'sandbox',
};

function seedToken() {
  try {
    return execFileSync('php', [path.join(__dirname, 'seed-flat-invoice.php')], {
      encoding: 'utf8',
      env: phpEnv,
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
  } catch (e) {
    const err = e.stderr?.toString?.() || e.message;
    throw new Error('seed-flat-invoice failed: ' + err);
  }
}

const token = seedToken();
console.log('Seeded flat invoice token');

const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', publicDir], {
  cwd: root,
  env: phpEnv,
  stdio: ['ignore', 'pipe', 'pipe'],
});

async function waitReady() {
  for (let i = 0; i < 50; i++) {
    try {
      const res = await fetch(`${base}/admin/login.php`);
      if (res.status > 0) return;
    } catch {
      /* retry */
    }
    await sleep(150);
  }
  throw new Error('PHP built-in server did not become ready');
}

let failed = 0;
try {
  await waitReady();

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });

  await page.goto(`${base}/admin/login.php`);
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin');
  await page.locator('form:has(#username) button[type="submit"]').click();
  await page.waitForURL(/\/admin\//);
  console.log('OK login → dashboard');

  await page.goto(`${base}/admin/company-edit.php`);
  await page.fill('#name', 'E2E Company ' + Date.now());
  await page.fill('#billing_email', 'billing-e2e@example.com');
  await page.locator('form:has(#name) button[type="submit"]').click();
  await page.waitForURL(/engagements\.php\?company_id=/);
  const companyId = new URL(page.url()).searchParams.get('company_id');
  if (!companyId) throw new Error('missing company_id after create');
  await page.goto(`${base}/admin/companies.php`);
  await page.waitForSelector('text=E2E Company');
  console.log('OK companies create/list');

  await page.goto(`${base}/admin/engagement-edit.php?company_id=${companyId}`);
  await page.fill('#name', 'Flat E2E Engagement');
  await page.evaluate(() => {
    document.getElementById('billing_mode').value = 'flat_tier';
    document.getElementById('billing_mode').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('tier1_amount_dollars').value = '2125';
    document.getElementById('tier2_amount_dollars').value = '5100';
  });
  await Promise.all([
    page.waitForURL(/engagements\.php/),
    page.locator('#engagement-form button[type="submit"]').click(),
  ]);
  await page.goto(`${base}/admin/engagements.php?company_id=${companyId}`);
  const bodyFlat = await page.content();
  if (!bodyFlat.includes('Flat E2E Engagement')) {
    throw new Error('Flat engagement missing. Page snippet: ' + bodyFlat.slice(0, 1500));
  }
  console.log('OK flat_tier engagement');

  await page.goto(`${base}/admin/engagement-edit.php?company_id=${companyId}`);
  await page.fill('#name', 'Hourly E2E Engagement');
  await page.evaluate(() => {
    document.getElementById('billing_mode').value = 'hourly';
    document.getElementById('billing_mode').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('hourly_rate_dollars').value = '100';
    document.getElementById('included_hours_per_month').value = '5';
  });
  await Promise.all([
    page.waitForURL(/engagements\.php/),
    page.locator('#engagement-form button[type="submit"]').click(),
  ]);
  await page.goto(`${base}/admin/engagements.php?company_id=${companyId}`);
  await page.waitForSelector('text=Hourly E2E Engagement');
  console.log('OK hourly engagement');

  await page.goto(`${base}/admin/invoices.php`);
  const engSelect = page.locator('#engagement_id');
  const options = await engSelect.locator('option').allTextContents();
  const flatIdx = options.findIndex((t) => /flat\/tier|Flat E2E|Siemens/i.test(t));
  if (flatIdx >= 0) {
    await engSelect.selectOption({ index: flatIdx });
  }
  const engVal = await engSelect.inputValue();
  await page.goto(`${base}/admin/invoices.php?engagement_id=${engVal}&anchor_month=2026-07&tier_key=tier1`);
  await page.waitForSelector('#tier_key');
  console.log('OK invoices tier picker');

  await page.goto(`${base}/invoice.php?t=${token}`);
  await page.waitForSelector('text=program fee');
  console.log('OK client invoice program-fee card');

  await browser.close();
  console.log('E2E passed');
} catch (e) {
  failed = 1;
  console.error('E2E failed:', e);
} finally {
  php.kill('SIGTERM');
  fs.rmSync(dbPath, { force: true });
}
process.exit(failed);
