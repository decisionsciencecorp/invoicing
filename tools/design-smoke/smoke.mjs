/**
 * Playwright design smoke — desktop + mobile screenshots for key routes.
 * Usage: BASE_URL=https://dev.invoicing.decisionsciencecorp.com node tools/design-smoke/smoke.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = (process.env.BASE_URL || 'https://dev.invoicing.decisionsciencecorp.com').replace(/\/$/, '');
const outDir = join(dirname(fileURLToPath(import.meta.url)), 'out');
mkdirSync(outDir, { recursive: true });

const routes = [
  ['login', '/admin/login.php'],
  ['help', '/admin/help.php'], // may redirect to login — still captures chrome
];

const browser = await chromium.launch();
for (const [name, size] of [
  ['mobile', { width: 390, height: 844 }],
  ['desktop', { width: 1280, height: 800 }],
]) {
  for (const [slug, path] of routes) {
    const page = await browser.newPage({ viewport: size });
    await page.goto(`${base}${path}`, { waitUntil: 'networkidle', timeout: 60000 });
    await page.screenshot({ path: join(outDir, `${slug}-${name}.png`), fullPage: true });
    await page.close();
  }
}
await browser.close();
console.log('Wrote screenshots to', outDir);
