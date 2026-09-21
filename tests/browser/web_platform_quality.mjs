import { chromium } from 'playwright-core';
import { access, mkdir } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';
import pngjs from 'pngjs';

const { PNG } = pngjs;
const baseUrl = process.argv[2] || process.env.COS_E2E_BASE_URL || 'http://127.0.0.1:8081';
const outputDir = resolve('tmp/web-quality');
await mkdir(outputDir, { recursive: true });

const candidates = [process.env.BROWSER_EXECUTABLE, process.env.CHROME_PATH, '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean);
let executablePath = null;
for (const candidate of candidates) {
  try { await access(candidate, fsConstants.X_OK); executablePath = candidate; break; } catch {}
}
if (!executablePath) throw new Error('No Chromium/Chrome executable found for Wave 12.23 browser quality suite.');

const browser = await chromium.launch({ headless: true, executablePath });
const absolute = (path) => new URL(path, baseUrl).toString();
const pages = [
  { name: 'home', path: '/' },
  { name: 'login', path: '/auth/login' },
  { name: 'property-catalog', path: '/property/catalog' },
];
const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, isMobile: false },
  { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
];

const assertVisual = async (buffer, label) => {
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
  if (opaque < 100 || colors.size < 6) throw new Error(`${label}: screenshot looks blank or degenerate (opaque=${opaque}, colors=${colors.size}).`);
};

const accessibilityIssues = async (page) => page.evaluate(() => {
  const issues = [];
  const html = document.documentElement;
  if (!(html.getAttribute('lang') || '').trim()) issues.push('document language is missing');
  if (!document.title.trim()) issues.push('document title is missing');
  const ids = [...document.querySelectorAll('[id]')].map((node) => node.id).filter(Boolean);
  const duplicates = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
  if (duplicates.length) issues.push(`duplicate ids: ${duplicates.join(', ')}`);
  const controls = [...document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select')];
  for (const control of controls) {
    const labelled = control.getAttribute('aria-label') || control.getAttribute('aria-labelledby') || (control.id && document.querySelector(`label[for="${CSS.escape(control.id)}"]`)) || control.closest('label');
    if (!labelled) issues.push(`unlabelled form control: ${control.tagName.toLowerCase()}#${control.id || '(no-id)'}`);
  }
  for (const image of document.querySelectorAll('img')) if (!image.hasAttribute('alt')) issues.push(`image missing alt: ${image.getAttribute('src') || '(no-src)'}`);
  for (const node of document.querySelectorAll('button, a[href]')) {
    const name = (node.getAttribute('aria-label') || node.getAttribute('title') || node.textContent || '').trim();
    if (!name) issues.push(`interactive element has no accessible name: ${node.tagName.toLowerCase()}`);
  }
  const overflow = Math.max(document.body.scrollWidth, html.scrollWidth) - window.innerWidth;
  if (overflow > 3) issues.push(`horizontal overflow: ${overflow}px`);
  return issues;
});

try {
  for (const profile of profiles) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.isMobile, reducedMotion: 'reduce', colorScheme: 'light' });
    for (const target of pages) {
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
      page.on('console', (message) => { if (message.type() === 'error') errors.push(`console: ${message.text()}`); });
      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) throw new Error(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
      const a11y = await accessibilityIssues(page);
      if (a11y.length) throw new Error(`${profile.name}/${target.name}: accessibility contract failed:\n- ${a11y.join('\n- ')}`);
      if (errors.length) throw new Error(`${profile.name}/${target.name}: browser errors:\n- ${errors.join('\n- ')}`);
      await page.keyboard.press('Tab');
      const focused = await page.evaluate(() => document.activeElement && document.activeElement !== document.body);
      if (!focused) throw new Error(`${profile.name}/${target.name}: keyboard focus did not enter the document.`);
      const screenshot = await page.screenshot({ path: resolve(outputDir, `${profile.name}-${target.name}.png`), fullPage: true });
      await assertVisual(screenshot, `${profile.name}/${target.name}`);
      await page.close();
    }
    await context.close();
  }
  console.log(JSON.stringify({ ok: true, suite: 'Wave 12.23 Web Platform quality', profiles: profiles.map((p) => p.name), pages: pages.map((p) => p.path), checks: ['visual','mobile','accessibility','browser-runtime'] }, null, 2));
} finally {
  await browser.close();
}
