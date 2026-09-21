import { chromium } from 'playwright-core';
import { access } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';

const baseUrl = process.argv[2] || process.env.COS_E2E_BASE_URL || 'http://127.0.0.1:8081';
const candidates = [process.env.BROWSER_EXECUTABLE, process.env.CHROME_PATH, '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean);
let executablePath = null;
for (const candidate of candidates) {
  try { await access(candidate, fsConstants.X_OK); executablePath = candidate; break; } catch {}
}
if (!executablePath) throw new Error('No Chromium/Chrome executable found for Wave 12.24 performance budget.');

const budgets = {
  ttfbMs: 1000,
  navigationMs: 3500,
  transferBytes: 4 * 1024 * 1024,
  jsBytes: 1500 * 1024,
  cssBytes: 1000 * 1024,
  resourceCount: 120,
  domNodes: 3000,
};

const targets = [
  { name: 'home', path: '/' },
  { name: 'login', path: '/auth/login' },
  { name: 'property-catalog', path: '/property/catalog' },
];

const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];

const browser = await chromium.launch({ headless: true, executablePath });
const absolute = (path) => new URL(path, baseUrl).toString();
const failures = [];
const report = [];

try {
  for (const profile of profiles) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.isMobile });
    for (const target of targets) {
      const page = await context.newPage();
      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) throw new Error(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);

      const metrics = await page.evaluate((origin) => {
        const nav = performance.getEntriesByType('navigation')[0];
        const resources = performance.getEntriesByType('resource').filter((entry) => {
          try { return new URL(entry.name).origin === origin; } catch { return false; }
        });
        const sum = (items, predicate = () => true) => items.filter(predicate).reduce((total, item) => total + (item.transferSize || 0), 0);
        return {
          ttfbMs: Math.round(nav?.responseStart || 0),
          navigationMs: Math.round(nav?.duration || 0),
          transferBytes: Math.round((nav?.transferSize || 0) + sum(resources)),
          jsBytes: Math.round(sum(resources, (item) => item.initiatorType === 'script' || item.name.endsWith('.js'))),
          cssBytes: Math.round(sum(resources, (item) => item.initiatorType === 'css' || item.name.includes('.css'))),
          resourceCount: resources.length,
          domNodes: document.querySelectorAll('*').length,
        };
      }, new URL(baseUrl).origin);

      report.push({ profile: profile.name, page: target.name, ...metrics });
      for (const [metric, limit] of Object.entries(budgets)) {
        if (metrics[metric] > limit) failures.push(`${profile.name}/${target.name}: ${metric}=${metrics[metric]} > ${limit}`);
      }
      await page.close();
    }
    await context.close();
  }

  console.log(JSON.stringify({ ok: failures.length === 0, budgets, report, failures }, null, 2));
  if (failures.length) throw new Error(`Wave 12.24 performance budgets failed:\n- ${failures.join('\n- ')}`);
} finally {
  await browser.close();
}
