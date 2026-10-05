import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { access, mkdir, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';
import pngjs from 'pngjs';

const { PNG } = pngjs;

const baseUrl = process.env.EXTERNAL_REFERENCE_BASE_URL || 'http://127.0.0.1:8081';
const email = process.env.EXTERNAL_REFERENCE_EMAIL || '';
const password = process.env.EXTERNAL_REFERENCE_PASSWORD || '';
if (!email || !password) throw new Error('External reference QA requires credentials for /cabinet.');

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
if (!executablePath) throw new Error('No Chrome/Chromium executable found.');

const outputDir = resolve(process.env.EXTERNAL_REFERENCE_OUTPUT_DIR || 'tmp/external-reference');
await mkdir(outputDir, { recursive: true });
const absolute = (path) => new URL(path, baseUrl).toString();

const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];

const targets = [
  { name: 'auth-login', path: '/auth/login', archetype: 'form_editor', auth: false },
  { name: 'cabinet', path: '/cabinet', archetype: 'portal', auth: true },
  { name: 'property-catalog', path: '/property/catalog', archetype: 'public_catalog', auth: false },
  { name: 'cos-en', path: '/cos/en', archetype: 'public_detail_marketing', auth: false },
];

const assertVisual = (buffer, label) => {
  const png = PNG.sync.read(buffer);
  const colors = new Set();
  let opaque = 0;
  const step = Math.max(4, Math.floor(Math.sqrt((png.width * png.height) / 12000)));
  for (let y = 0; y < png.height; y += step) {
    for (let x = 0; x < png.width; x += step) {
      const o = (y * png.width + x) * 4;
      if (png.data[o + 3] > 10) opaque += 1;
      colors.add(`${png.data[o] >> 4},${png.data[o + 1] >> 4},${png.data[o + 2] >> 4}`);
    }
  }
  if (opaque < 100 || colors.size < 6) {
    throw new Error(`${label}: screenshot looks blank or degenerate.`);
  }
};

const browser = await chromium.launch({ headless: true, executablePath });
const failures = [];

try {
  const loginContext = await browser.newContext({ viewport: profiles[0].viewport, reducedMotion: 'reduce' });
  const loginPage = await loginContext.newPage();
  await loginPage.goto(absolute('/auth/login'), { waitUntil: 'networkidle' });
  await loginPage.locator('input[name="email"]').fill(email);
  await loginPage.locator('input[name="password"]').fill(password);
  await Promise.all([
    loginPage.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    loginPage.locator('button[type="submit"]').click(),
  ]);
  const authState = await loginContext.storageState();
  await loginContext.close();

  for (const profile of profiles) {
    for (const target of targets) {
      const context = await browser.newContext({
        viewport: profile.viewport,
        isMobile: profile.isMobile,
        reducedMotion: 'reduce',
        colorScheme: 'light',
        storageState: target.auth ? authState : undefined,
      });
      const page = await context.newPage();
      const browserErrors = [];
      page.on('pageerror', (error) => browserErrors.push(`pageerror: ${error.message}`));
      page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
          browserErrors.push(`console: ${message.text()}`);
        }
      });

      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle', timeout: 30000 });
      if (!response || response.status() >= 400) {
        failures.push(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
        await context.close();
        continue;
      }

      if (!await page.locator(`[data-cos-archetype="${target.archetype}"]`).count()) {
        failures.push(`${profile.name}/${target.name}: missing archetype ${target.archetype}`);
      }

      if (await page.locator('.cos-shell__sidebar').count()) {
        failures.push(`${profile.name}/${target.name}: internal Workspace sidebar leaked into external UX`);
      }

      const overflow = await page.evaluate(() => (
        Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)
        - document.documentElement.clientWidth
      ));
      if (overflow > 3) failures.push(`${profile.name}/${target.name}: horizontal overflow ${overflow}px`);

      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => document.activeElement && document.activeElement !== document.body);
      if (!focused) failures.push(`${profile.name}/${target.name}: keyboard focus did not enter document`);

      const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'])
        .analyze();
      await writeFile(
        resolve(outputDir, `${profile.name}-${target.name}.axe.json`),
        JSON.stringify(axe, null, 2),
      );
      if (axe.violations.length) {
        failures.push(`${profile.name}/${target.name}: accessibility ${axe.violations.map(v => v.id).join(', ')}`);
      }

      if (browserErrors.length) failures.push(`${profile.name}/${target.name}: ${browserErrors.join('; ')}`);

      const screenshot = await page.screenshot({
        path: resolve(outputDir, `${profile.name}-${target.name}.png`),
        fullPage: true,
      });
      assertVisual(screenshot, `${profile.name}/${target.name}`);

      await context.close();
    }
  }

  await writeFile(
    resolve(outputDir, 'summary.json'),
    JSON.stringify({
      ok: failures.length === 0,
      suite: 'COS EX-006 External Reference browser QA',
      pages: targets,
      profiles: profiles.map((profile) => profile.name),
      checks: ['http','archetype','external-boundary','overflow','keyboard','axe-aa','browser-errors','screenshot'],
      failures,
    }, null, 2),
  );
} finally {
  await browser.close();
}

if (failures.length) throw new Error(`External Reference browser QA failed:\n${failures.join('\n')}`);
console.log(JSON.stringify({ ok: true, pages: 4, profiles: 2 }, null, 2));
