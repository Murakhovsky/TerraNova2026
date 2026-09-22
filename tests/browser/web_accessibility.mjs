import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';

const baseUrl = process.argv[2] || process.env.COS_E2E_BASE_URL || 'http://127.0.0.1:8081';
const outputDir = resolve(process.env.COS_A11Y_OUTPUT_DIR || 'tmp/web-accessibility');
await mkdir(outputDir, { recursive: true });

const candidates = [
  process.env.BROWSER_EXECUTABLE,
  process.env.CHROME_PATH,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);

let executablePath = null;
for (const candidate of candidates) {
  try {
    await access(candidate, fsConstants.X_OK);
    executablePath = candidate;
    break;
  } catch {}
}
if (!executablePath) throw new Error('No Chromium/Chrome executable found for PHASE 15 accessibility suite.');

const pages = [
  { name: 'home', path: '/' },
  { name: 'login', path: '/auth/login' },
  { name: 'property-catalog', path: '/property/catalog' },
];
const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];
const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const absolute = (path) => new URL(path, baseUrl).toString();

const browser = await chromium.launch({ headless: true, executablePath });
const failures = [];

try {
  for (const profile of profiles) {
    const context = await browser.newContext({
      viewport: profile.viewport,
      isMobile: profile.isMobile,
      reducedMotion: 'reduce',
      colorScheme: 'light',
    });

    for (const target of pages) {
      const page = await context.newPage();
      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) {
        throw new Error(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
      }

      const result = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
      const evidencePath = resolve(outputDir, `${profile.name}-${target.name}.json`);
      await writeFile(evidencePath, JSON.stringify(result, null, 2));

      if (result.violations.length) {
        const summary = result.violations.map((violation) => {
          const targets = violation.nodes
            .flatMap((node) => node.target)
            .slice(0, 4)
            .join(', ');
          return `${violation.id} [${violation.impact || 'unknown'}] ${targets}`;
        });
        failures.push(`${profile.name}/${target.name}:\n- ${summary.join('\n- ')}`);
      }

      await page.close();
    }

    await context.close();
  }
} finally {
  await browser.close();
}

if (failures.length) {
  throw new Error(`PHASE 15 WCAG accessibility violations:\n${failures.join('\n')}`);
}

console.log(JSON.stringify({
  ok: true,
  suite: 'PHASE 15 WCAG accessibility',
  engine: '@axe-core/playwright@4.13.0',
  profiles: profiles.map((profile) => profile.name),
  pages: pages.map((page) => page.path),
  tags: wcagTags,
}, null, 2));
