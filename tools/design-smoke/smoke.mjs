/**
 * Minimal Playwright design smoke — run against a live base URL.
 * Usage: BASE_URL=https://dev.invoicing.decisionsciencecorp.com node tools/design-smoke/smoke.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = (process.env.BASE_URL || 'https://dev.invoicing.decisionsciencecorp.com').replace(/\/$/, '');
const outDir = join(dirname(fileURLToPath(import.meta.url)), 'out');
mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
for (const [name, size] of [
  ['mobile', { width: 390, height: 844 }],
  ['desktop', { width: 1280, height: 800 }],
]) {
  const page = await browser.newPage({ viewport: size });
  await page.goto(`${base}/admin/login.php`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: join(outDir, `login-${name}.png`), fullPage: true });
  await page.close();
}
await browser.close();
console.log('Wrote screenshots to', outDir);
