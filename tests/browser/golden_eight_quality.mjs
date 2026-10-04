import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';
import pngjs from 'pngjs';

const { PNG } = pngjs;

const baseUrl = process.env.GOLDEN_EIGHT_BASE_URL
  || process.env.SALES_E2E_BASE_URL
  || 'http://127.0.0.1:8081';
const email = process.env.GOLDEN_EIGHT_EMAIL
  || process.env.SALES_E2E_EMAIL
  || '';
const password = process.env.GOLDEN_EIGHT_PASSWORD
  || process.env.SALES_E2E_PASSWORD
  || '';

if (!email || !password) {
  throw new Error('Golden Eight browser QA requires GOLDEN_EIGHT_EMAIL and GOLDEN_EIGHT_PASSWORD.');
}

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

if (!executablePath) {
  throw new Error('No Chrome/Chromium executable found for Golden Eight browser QA.');
}

const outputDir = resolve(process.env.GOLDEN_EIGHT_OUTPUT_DIR || 'tmp/golden-eight');
await mkdir(outputDir, { recursive: true });

const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];

const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const absolute = (path) => new URL(path, baseUrl).toString();

const assertVisual = (buffer, label) => {
  const png = PNG.sync.read(buffer);
  const colors = new Set();
  let opaque = 0;
  const step = Math.max(4, Math.floor(Math.sqrt((png.width * png.height) / 14000)));

  for (let y = 0; y < png.height; y += step) {
    for (let x = 0; x < png.width; x += step) {
      const offset = (y * png.width + x) * 4;
      if (png.data[offset + 3] > 10) opaque += 1;
      colors.add(`${png.data[offset] >> 4},${png.data[offset + 1] >> 4},${png.data[offset + 2] >> 4}`);
    }
  }

  if (opaque < 100 || colors.size < 6) {
    throw new Error(`${label}: screenshot looks blank or degenerate (opaque=${opaque}, colors=${colors.size}).`);
  }
};

const browser = await chromium.launch({ headless: true, executablePath });
const failures = [];

try {
  const authContext = await browser.newContext({
    viewport: profiles[0].viewport,
    reducedMotion: 'reduce',
    colorScheme: 'light',
  });
  const authPage = await authContext.newPage();

  const loginResponse = await authPage.goto(absolute('/auth/login'), { waitUntil: 'networkidle' });
  if (!loginResponse || loginResponse.status() >= 400) {
    throw new Error(`Login page returned HTTP ${loginResponse?.status() ?? 'no response'}`);
  }

  await authPage.locator('input[name="email"]').fill(email);
  await authPage.locator('input[name="password"]').fill(password);
  await Promise.all([
    authPage.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    authPage.locator('button[type="submit"]').click(),
  ]);

  const pipelineResponse = await authPage.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' });
  if (!pipelineResponse || pipelineResponse.status() >= 400) {
    throw new Error(`Deal discovery returned HTTP ${pipelineResponse?.status() ?? 'no response'}`);
  }

  const firstDeal = authPage.locator('[data-sales-deal-card]').first();
  if (!await firstDeal.count()) {
    throw new Error('Golden Eight fixture requires at least one Sales Deal card.');
  }

  const dealHref = await firstDeal.getAttribute('href');
  const dealId = await firstDeal.getAttribute('data-deal-id');
  const dealPath = dealHref || (dealId ? `/sales/deals/${dealId}` : '');
  if (!dealPath) {
    throw new Error('Golden Eight Deal fixture must expose href or data-deal-id.');
  }

  const storageState = await authContext.storageState();
  await authContext.close();

  const targets = [
    { name: 'executive-dashboard', path: '/admin', archetype: 'executive_dashboard' },
    { name: 'sales-dashboard', path: '/sales/dashboard', archetype: 'domain_dashboard' },
    { name: 'sales-today', path: '/sales/today', archetype: 'operational_queue' },
    { name: 'sales-leads', path: '/sales/leads', archetype: 'collection' },
    { name: 'sales-deal', path: dealPath, archetype: 'entity_workspace' },
    { name: 'sales-pipeline', path: '/sales/pipeline', archetype: 'process_pipeline' },
    { name: 'growth-overview', path: '/growth', archetype: 'domain_dashboard' },
    { name: 'property-map', path: '/property/map', archetype: 'map_spatial' },
  ];

  for (const profile of profiles) {
    const context = await browser.newContext({
      viewport: profile.viewport,
      isMobile: profile.isMobile,
      reducedMotion: 'reduce',
      colorScheme: 'light',
      storageState,
    });

    for (const target of targets) {
      const page = await context.newPage();
      const browserErrors = [];

      page.on('pageerror', (error) => browserErrors.push(`pageerror: ${error.stack || error.message}`));
      page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
          browserErrors.push(`console: ${message.text()}`);
        }
      });

      const response = await page.goto(absolute(target.path), {
        waitUntil: 'networkidle',
        timeout: 30000,
      });

      if (!response || response.status() >= 400) {
        failures.push(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
        await page.close();
        continue;
      }

      const archetype = page.locator(`[data-cos-archetype="${target.archetype}"]`);
      if (!await archetype.count()) {
        failures.push(`${profile.name}/${target.name}: canonical archetype ${target.archetype} marker missing`);
      }

      const overflow = await page.evaluate(() => (
        Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)
        - document.documentElement.clientWidth
      ));
      if (overflow > 3) {
        failures.push(`${profile.name}/${target.name}: horizontal overflow ${overflow}px`);
      }

      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => document.activeElement && document.activeElement !== document.body);
      if (!focused) {
        failures.push(`${profile.name}/${target.name}: keyboard focus did not enter document`);
      }

      const axe = await new AxeBuilder({ page }).withTags(wcagTags).analyze();
      await writeFile(
        resolve(outputDir, `${profile.name}-${target.name}.axe.json`),
        JSON.stringify(axe, null, 2),
      );

      if (axe.violations.length) {
        const summary = axe.violations.map((violation) => {
          const nodes = violation.nodes
            .flatMap((node) => node.target)
            .slice(0, 4)
            .join(', ');
          return `${violation.id} [${violation.impact || 'unknown'}] ${nodes}`;
        });
        failures.push(`${profile.name}/${target.name} accessibility:\n- ${summary.join('\n- ')}`);
      }

      if (browserErrors.length) {
        failures.push(`${profile.name}/${target.name} browser errors:\n- ${browserErrors.join('\n- ')}`);
      }

      const screenshot = await page.screenshot({
        path: resolve(outputDir, `${profile.name}-${target.name}.png`),
        fullPage: true,
      });
      assertVisual(screenshot, `${profile.name}/${target.name}`);

      await page.close();
    }

    await context.close();
  }

  await writeFile(
    resolve(outputDir, 'summary.json'),
    JSON.stringify({
      ok: failures.length === 0,
      suite: 'COS Experience V1 Golden Eight browser QA',
      profiles: profiles.map((profile) => profile.name),
      pages: targets.map((target) => ({
        name: target.name,
        path: target.path,
        archetype: target.archetype,
      })),
      checks: ['http', 'archetype', 'responsive-overflow', 'keyboard', 'axe-wcag-aa', 'screenshot', 'browser-errors'],
      failures,
    }, null, 2),
  );
} finally {
  await browser.close();
}

if (failures.length) {
  throw new Error(`Golden Eight browser QA failed:\n${failures.join('\n')}`);
}

console.log(JSON.stringify({
  ok: true,
  suite: 'COS Experience V1 Golden Eight browser QA',
  profiles: profiles.map((profile) => profile.name),
  pages: 8,
  checks: ['http', 'archetype', 'responsive-overflow', 'keyboard', 'axe-wcag-aa', 'screenshot', 'browser-errors'],
}, null, 2));
