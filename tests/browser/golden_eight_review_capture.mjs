import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';

// Supplemental evidence for the human Golden 8 review.
// Canonical 16-observation QA and its validated manifest remain untouched.
const baseUrl = process.env.GOLDEN_EIGHT_BASE_URL || '';
const email = process.env.GOLDEN_EIGHT_EMAIL || '';
const password = process.env.GOLDEN_EIGHT_PASSWORD || '';
if (!baseUrl || !email || !password) {
  throw new Error('GOLDEN_EIGHT_BASE_URL, GOLDEN_EIGHT_EMAIL and GOLDEN_EIGHT_PASSWORD are required.');
}

const paths = [
  process.env.CHROME_PATH,
  process.env.BROWSER_EXECUTABLE,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);
let executablePath;
for (const path of paths) {
  try {
    await access(path, fsConstants.X_OK);
    executablePath = path;
    break;
  } catch {}
}
if (!executablePath) throw new Error('Golden 8 review capture requires Chrome/Chromium.');

const absolute = (path) => new URL(path, baseUrl).toString();
const root = resolve(process.env.GOLDEN_EIGHT_OUTPUT_DIR || 'tmp/golden-eight');
const outputDir = resolve(root, 'review-support');
await mkdir(outputDir, { recursive: true });

const profiles = [
  { name: 'tablet', viewport: { width: 768, height: 1024 }, isMobile: true },
  { name: 'small-mobile', viewport: { width: 320, height: 700 }, isMobile: true },
];
const targets = [
  { name: 'executive-dashboard', path: '/admin', archetype: 'executive_dashboard' },
  { name: 'sales-dashboard', path: '/sales/dashboard', archetype: 'domain_dashboard' },
  { name: 'sales-today', path: '/sales/today', archetype: 'operational_queue' },
  { name: 'sales-leads', path: '/sales/leads', archetype: 'collection' },
  { name: 'sales-deal', path: null, archetype: 'entity_workspace' },
  { name: 'sales-pipeline', path: '/sales/pipeline', archetype: 'process_pipeline' },
  { name: 'growth-overview', path: '/growth', archetype: 'domain_dashboard' },
  { name: 'property-map', path: '/property/map', archetype: 'map_spatial' },
];

const observations = [];
const failures = [];
const browser = await chromium.launch({ headless: true, executablePath });

try {
  const loginContext = await browser.newContext({
    viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce',
  });
  const loginPage = await loginContext.newPage();
  const loginResponse = await loginPage.goto(absolute('/auth/login'), { waitUntil: 'networkidle' });
  if (!loginResponse || loginResponse.status() >= 400) {
    throw new Error('Login returned HTTP ' + (loginResponse?.status() ?? 'no response'));
  }
  await loginPage.locator('input[name="email"]').fill(email);
  await loginPage.locator('input[name="password"]').fill(password);
  await Promise.all([
    loginPage.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    loginPage.locator('button[type="submit"]').click(),
  ]);

  const pipeline = await loginPage.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' });
  if (!pipeline || pipeline.status() >= 400) throw new Error('Sales Pipeline fixture unavailable.');
  const dealCard = loginPage.locator('[data-sales-deal-card]').first();
  if (!await dealCard.count()) throw new Error('No fixture deal found in Sales Pipeline.');
  const dealLink = await dealCard.getAttribute('href')
    || await dealCard.locator('a[href^="/sales/deals/"]').first().getAttribute('href').catch(() => null);
  const dealId = await dealCard.getAttribute('data-deal-id');
  targets[4].path = dealLink || (dealId ? '/sales/deals/' + encodeURIComponent(dealId) : null);
  if (!targets[4].path) throw new Error('Fixture deal must expose a route or data-deal-id.');
  const storageState = await loginContext.storageState();
  await loginContext.close();

  for (const profile of profiles) {
    const context = await browser.newContext({
      viewport: profile.viewport, isMobile: profile.isMobile,
      reducedMotion: 'reduce', colorScheme: 'light', storageState,
    });
    for (const target of targets) {
      const page = await context.newPage();
      const errors = [];
      const id = profile.name + '/' + target.name;
      page.on('pageerror', (error) => errors.push('pageerror: ' + error.message));
      page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
          errors.push('console: ' + message.text());
        }
      });
      try {
        const response = await page.goto(absolute(target.path), {
          waitUntil: 'networkidle', timeout: 30000,
        });
        const status = response?.status() ?? 0;
        if (status < 200 || status >= 400) errors.push('HTTP ' + status);
        if (status >= 200 && status < 400) {
          const archetype = await page.locator('[data-cos-archetype="' + target.archetype + '"]').count();
          if (!archetype) errors.push('Canonical archetype missing: ' + target.archetype);
        }
        const overflow = await page.evaluate(() => Math.max(
          document.documentElement.scrollWidth, document.body.scrollWidth,
        ) - document.documentElement.clientWidth);
        if (overflow > 3) errors.push('Horizontal overflow ' + overflow + 'px');

        await page.keyboard.press('Tab');
        const focusReached = await page.evaluate(
          () => document.activeElement && document.activeElement !== document.body,
        );
        if (!focusReached) errors.push('Tab did not enter the document');

        const axe = await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
          .analyze();
        const axeFile = profile.name + '-' + target.name + '.axe.json';
        await writeFile(resolve(outputDir, axeFile), JSON.stringify(axe, null, 2));
        if (axe.violations.length) {
          errors.push('Axe violations: ' + axe.violations.map((v) => v.id).join(', '));
        }

        const screenshot = profile.name + '-' + target.name + '.png';
        await page.screenshot({ path: resolve(outputDir, screenshot), fullPage: true });
        observations.push({
          profile: profile.name, viewport: profile.viewport, page: target.name,
          route: target.path, status, overflow_px: overflow,
          keyboard_focus_reached: Boolean(focusReached),
          axe_violations: axe.violations.length, screenshot, axe_report: axeFile,
          errors,
        });
      } catch (error) {
        errors.push(error instanceof Error ? error.message : String(error));
        observations.push({
          profile: profile.name, viewport: profile.viewport, page: target.name,
          route: target.path, errors,
        });
      } finally {
        if (errors.length) failures.push(id + ': ' + errors.join('; '));
        await page.close();
      }
    }
    await context.close();
  }
} catch (error) {
  failures.push('setup: ' + (error instanceof Error ? error.message : String(error)));
} finally {
  await browser.close();
  const summary = {
    schema: 1,
    suite: 'COS Golden 8 supplemental human-review evidence',
    canonical_suite: 'tests/browser/golden_eight_quality.mjs',
    supplemental_only: true,
    does_not_modify: [
      'summary.json', 'page-contract-qa.json',
      'golden-review-checklist.yaml', 'golden-decisions.yaml',
      'Page Contract quality scores', 'V1_READY',
    ],
    human_acceptance: 'PENDING',
    source_commit_sha: process.env.GITHUB_SHA || null,
    workflow_run_id: process.env.GITHUB_RUN_ID || null,
    workflow_run_attempt: process.env.GITHUB_RUN_ATTEMPT || null,
    recorded_at: new Date().toISOString(),
    profiles,
    observations,
    failures,
  };
  await writeFile(resolve(outputDir, 'summary.json'), JSON.stringify(summary, null, 2));
}
console.log(JSON.stringify({
  suite: 'COS Golden 8 supplemental human-review evidence',
  observations: observations.length, failures,
  human_acceptance: 'PENDING',
}, null, 2));
if (failures.length) process.exitCode = 1;
