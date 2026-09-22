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
  { name: 'register', path: '/auth/register' },
  { name: 'blog', path: '/blog' },
  { name: 'property-catalog', path: '/property/catalog' },
  { name: 'property-map', path: '/property/map' },
  { name: 'property-favourites', path: '/property/favour' },
  { name: 'property-submit', path: '/property/submit' },
  { name: 'services', path: '/services' },
  { name: 'contacts', path: '/contacts' },
];
const profiles = [
  { name: 'desktop-light', viewport: { width: 1440, height: 1000 }, isMobile: false, colorScheme: 'light' },
  { name: 'desktop-dark', viewport: { width: 1440, height: 1000 }, isMobile: false, colorScheme: 'dark' },
  { name: 'mobile-light', viewport: { width: 390, height: 844 }, isMobile: true, colorScheme: 'light' },
  { name: 'mobile-dark', viewport: { width: 390, height: 844 }, isMobile: true, colorScheme: 'dark' },
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

const layoutIssues = async (page, expectedScheme) => page.evaluate((scheme) => {
  const issues = [];
  const html = document.documentElement;
  const body = document.body;
  const bodyStyle = getComputedStyle(body);
  const fontSize = Number.parseFloat(bodyStyle.fontSize || '0');
  const lineHeight = Number.parseFloat(bodyStyle.lineHeight || '0');

  if (fontSize && fontSize < 14) issues.push(`body font-size is too small: ${fontSize}px`);
  if (fontSize && lineHeight && lineHeight < fontSize * 1.15) {
    issues.push(`body line-height is too tight: ${lineHeight}px for ${fontSize}px font`);
  }

  const expectedDark = scheme === 'dark';
  if (matchMedia('(prefers-color-scheme: dark)').matches !== expectedDark) {
    issues.push(`browser color-scheme preference mismatch: expected ${scheme}`);
  }

  const main = document.querySelector('main');
  if (!main) {
    issues.push('main landmark is missing');
  } else {
    const rect = main.getBoundingClientRect();
    if (rect.width < 200 || rect.height < 80) issues.push(`main geometry is suspicious: ${Math.round(rect.width)}x${Math.round(rect.height)}`);
    if (rect.right > window.innerWidth + 3 || rect.left < -3) issues.push('main overflows viewport');
  }

  const visible = (node) => {
    const style = getComputedStyle(node);
    const rect = node.getBoundingClientRect();
    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
  };

  for (const control of document.querySelectorAll('input:not([type="hidden"]), textarea, select, button')) {
    if (!visible(control)) continue;
    const rect = control.getBoundingClientRect();
    if (rect.right > window.innerWidth + 3 || rect.left < -3) {
      issues.push(`form control clips viewport: ${control.tagName.toLowerCase()} ${Math.round(rect.left)}..${Math.round(rect.right)}`);
    }
    if (rect.height < 28) {
      issues.push(`form control target is too short: ${control.tagName.toLowerCase()} ${Math.round(rect.height)}px`);
    }
  }

  for (const node of document.querySelectorAll('body *')) {
    if (!visible(node)) continue;
    const style = getComputedStyle(node);
    if (style.position !== 'sticky' && style.position !== 'fixed') continue;
    const rect = node.getBoundingClientRect();
    if (rect.width > window.innerWidth + 4 || rect.left < -4 || rect.right > window.innerWidth + 4) {
      issues.push(`sticky/fixed element overflows viewport: ${node.tagName.toLowerCase()}.${typeof node.className === 'string' ? node.className : ''}`);
    }
    if (rect.height > window.innerHeight * 0.75) {
      issues.push(`sticky/fixed element obscures too much viewport: ${Math.round(rect.height)}px`);
    }
  }

  const rootWidth = Math.max(body.scrollWidth, html.scrollWidth);
  if (rootWidth > window.innerWidth + 3) issues.push(`document overflow after layout audit: ${rootWidth - window.innerWidth}px`);

  return issues;
}, expectedScheme);

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
  if (overflow > 3) {
    const offenders = [...document.querySelectorAll('body *')]
      .map((node) => {
        const rect = node.getBoundingClientRect();
        return {
          tag: node.tagName.toLowerCase(),
          id: node.id || '',
          className: typeof node.className === 'string' ? node.className : '',
          left: Math.round(rect.left),
          right: Math.round(rect.right),
          width: Math.round(rect.width),
          scrollWidth: node.scrollWidth,
        };
      })
      .filter((item) => item.right > window.innerWidth + 3 || item.left < -3)
      .sort((a, b) => (b.right - window.innerWidth) - (a.right - window.innerWidth))
      .slice(0, 8);
    issues.push(`horizontal overflow: ${overflow}px; offenders=${JSON.stringify(offenders)}`);
  }
  return issues;
});

try {
  for (const profile of profiles) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.isMobile, reducedMotion: 'reduce', colorScheme: profile.colorScheme });
    for (const target of pages) {
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
      page.on('response', (response) => {
        if (response.status() >= 400 && response.url().startsWith(baseUrl)) {
          errors.push(`http ${response.status()}: ${response.url()}`);
        }
      });
      page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
          errors.push(`console: ${message.text()}`);
        }
      });
      const response = await page.goto(absolute(target.path), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) throw new Error(`${profile.name}/${target.name}: HTTP ${response?.status() ?? 'no response'}`);
      const a11y = await accessibilityIssues(page);
      if (a11y.length) throw new Error(`${profile.name}/${target.name}: accessibility contract failed:\n- ${a11y.join('\n- ')}`);
      const layout = await layoutIssues(page, profile.colorScheme);
      if (layout.length) throw new Error(`${profile.name}/${target.name}: layout contract failed:\n- ${layout.join('\n- ')}`);
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
  console.log(JSON.stringify({ ok: true, suite: 'Wave 12.23 Web Platform quality', profiles: profiles.map((p) => p.name), pages: pages.map((p) => p.path), checks: ['visual','desktop-mobile','light-dark-preference','typography','overflow','sticky-fixed','forms','accessibility','browser-runtime'] }, null, 2));
} finally {
  await browser.close();
}
