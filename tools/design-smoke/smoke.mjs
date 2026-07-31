/**
 * Playwright design smoke — desktop + mobile screenshots.
 * Live:  BASE_URL=https://dev.invoicing.decisionsciencecorp.com node tools/design-smoke/smoke.mjs
 * Local: BASE_URL=http://127.0.0.1:8765 ADMIN_USER=admin ADMIN_PASS=admin node tools/design-smoke/smoke.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = (process.env.BASE_URL || 'https://dev.invoicing.decisionsciencecorp.com').replace(/\/$/, '');
const adminUser = process.env.ADMIN_USER || '';
const adminPass = process.env.ADMIN_PASS || '';
const outDir = join(dirname(fileURLToPath(import.meta.url)), 'out');
mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
const report = [];

async function shot(page, slug, sizeName) {
  const path = join(outDir, `${slug}-${sizeName}.png`);
  await page.screenshot({ path, fullPage: true });
  report.push(path);
}

async function loginIfConfigured(page) {
  if (!adminUser || !adminPass) return false;
  await page.goto(`${base}/admin/login.php`, { waitUntil: 'networkidle', timeout: 60000 });
  await page.fill('#username', adminUser);
  await page.fill('#password', adminPass);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle', timeout: 60000 }),
    page.click('button[type="submit"]'),
  ]);
  const url = page.url();
  if (url.includes('login.php')) {
    report.push('LOGIN_FAILED');
    return false;
  }
  return true;
}

for (const [sizeName, size] of [
  ['mobile', { width: 390, height: 844 }],
  ['desktop', { width: 1280, height: 800 }],
]) {
  const context = await browser.newContext({ viewport: size });
  const page = await context.newPage();

  // Asset failure detector (CDN / 404 / blocked)
  const failed = [];
  page.on('response', (res) => {
    const u = res.url();
    if (!/\.(css|js|woff2?)(\?|$)/i.test(u) && !u.includes('bootstrap')) return;
    if (res.status() >= 400) failed.push(`${res.status()} ${u}`);
  });

  await page.goto(`${base}/admin/login.php`, { waitUntil: 'networkidle', timeout: 60000 });
  await shot(page, 'login', sizeName);

  const loggedIn = await loginIfConfigured(page);
  if (loggedIn) {
    for (const [slug, path] of [
      ['dashboard', '/admin/index.php'],
      ['invoices', '/admin/invoices.php'],
      ['companies', '/admin/companies.php'],
      ['settings-appearance', '/admin/appearance.php'],
      ['settings-square', '/admin/config.php'],
      ['settings-webhooks', '/admin/webhooks.php'],
      ['settings-webhooks-signing', '/admin/webhooks.php?section=signing'],
      ['settings-users', '/admin/users.php'],
      ['settings-site', '/admin/site.php'],
      ['dashboard-hey', '/admin/index.php?preview_skin=hey'],
      ['help', '/admin/help.php'],
    ]) {
      await page.goto(`${base}${path}`, { waitUntil: 'networkidle', timeout: 60000 });
      await shot(page, slug, sizeName);
    }
  } else {
    await page.goto(`${base}/admin/help.php`, { waitUntil: 'networkidle', timeout: 60000 });
    await shot(page, 'help-redir', sizeName);
  }

  if (failed.length) {
    writeFileSync(join(outDir, `asset-failures-${sizeName}.txt`), failed.join('\n'));
    report.push(`ASSET_FAILURES_${sizeName}:${failed.length}`);
  }

  await context.close();
}

await browser.close();
console.log(report.join('\n'));
console.log('Wrote screenshots to', outDir);
