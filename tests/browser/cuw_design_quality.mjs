import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { PNG } from 'pngjs';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { gzipSync, deflateSync, inflateSync } from 'node:zlib';
import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import { constants } from 'node:fs';
import { resolve, extname, sep } from 'node:path';

const output = resolve(process.env.CUW_QUALITY_OUTPUT_DIR || 'tmp/cuw-design-quality');
const fixtures = resolve(process.env.CUW_QUALITY_FIXTURE_DIR || 'tmp/cuw-quality-fixtures');
const publicDir = resolve('symfony/public');
const baselineFile = resolve('tests/browser/baselines/cuw-design-quality.json');
const update = process.argv.includes('--update-baseline');
const authenticated = Boolean(process.env.CUW_QUALITY_BASE_URL);
if (authenticated && update) throw new Error('Baselines may only be updated from synthetic local fixtures.');
if (authenticated && !process.env.CUW_QUALITY_STORAGE_STATE) throw new Error('An authenticated manager storage state is required for /dev/ui.');
await mkdir(output, { recursive: true });
const candidates = [process.env.CHROME_PATH, process.env.BROWSER_EXECUTABLE, '/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/chromium-browser'].filter(Boolean);
let executablePath;
for (const candidate of candidates) {
  try { await access(candidate, constants.X_OK); executablePath = candidate; break; } catch {}
}
if (!executablePath) throw new Error('Chromium executable unavailable');

// Only local synthetic HTML and compiled public assets are served; no app/API or production access.
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json', '.webmanifest': 'application/manifest+json', '.png': 'image/png', '.woff2': 'font/woff2' };
let server;
let base = process.env.CUW_QUALITY_BASE_URL;
if (!authenticated) {
  server = createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      let file;
      if (/^\/(catalog|shell)-(uk|en)\.html$/.test(url.pathname)) file = resolve(fixtures, '.' + url.pathname);
      else if (url.pathname.startsWith('/assets/') || ['/manifest.webmanifest', '/icons/cos-192.svg', '/sw.js'].includes(url.pathname)) {
        file = resolve(publicDir, '.' + url.pathname);
        assert(file.startsWith(publicDir + sep));
      } else { res.writeHead(404).end('Fixture only'); return; }
      const body = await readFile(file);
      const zip = /gzip/.test(req.headers['accept-encoding'] || '') && /\.(?:html|js|css|svg|json|webmanifest)$/.test(file);
      const payload = zip ? gzipSync(body) : body;
      res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': url.pathname.startsWith('/assets/') ? 'public, max-age=31536000, immutable' : 'no-store', 'Content-Length': payload.length, ...(zip ? { 'Content-Encoding': 'gzip' } : {}) });
      res.end(payload);
    } catch { if (!res.headersSent) res.writeHead(404); res.end(); }
  });
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  base = `http://127.0.0.1:${server.address().port}`;
}

const profiles = [
  { name: 'desktop-light-en', locale: 'en', theme: 'light', density: 'comfortable', viewport: { width: 1440, height: 1000 }, scheme: 'light' },
  { name: 'desktop-dark-uk', locale: 'uk', theme: 'dark', density: 'compact', viewport: { width: 1440, height: 1000 }, scheme: 'dark' },
  { name: 'desktop-system-dark-en', locale: 'en', theme: 'system', density: 'compact', viewport: { width: 1440, height: 1000 }, scheme: 'dark' },
  { name: 'desktop-system-light-uk', locale: 'uk', theme: 'system', density: 'comfortable', viewport: { width: 1440, height: 1000 }, scheme: 'light' },
  { name: 'mobile-light-uk', locale: 'uk', theme: 'light', density: 'compact', viewport: { width: 390, height: 844 }, scheme: 'light' },
  { name: 'mobile-dark-en', locale: 'en', theme: 'dark', density: 'comfortable', viewport: { width: 390, height: 844 }, scheme: 'dark' },
  { name: 'mobile-system-dark-uk', locale: 'uk', theme: 'system', density: 'comfortable', viewport: { width: 390, height: 844 }, scheme: 'dark' },
  { name: 'mobile-system-light-en', locale: 'en', theme: 'system', density: 'compact', viewport: { width: 390, height: 844 }, scheme: 'light' },
];
// The exhaustive development catalog loads the full AssetMapper/Stimulus inventory.
 // Production route budgets (including 120 resources) remain in web_performance_budget.mjs.
const budgets = { domNodes: 2500, jsBytes: 1500 * 1024, cssBytes: 1024 * 1024, decodedJsBytes: 3 * 1024 * 1024, transferBytes: 4 * 1024 * 1024, resources: 180, ttfbMs: 1000, navigationMs: 3500 };
const wcag = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const snapshots = {};
const failures = [];
const results = [];
const shellFindings = [];
const baseline = update || authenticated ? null : JSON.parse(await readFile(baselineFile, 'utf8'));
const browser = await chromium.launch({ headless: true, executablePath, args: ['--no-sandbox'] });

// Store compact perceptual reference alongside raw local screenshot evidence.
// Grid averages detect layout/color regressions without requiring binary golden files.
function signature(buffer) {
  const png = PNG.sync.read(buffer);
  const cells = 40;
  const data = [];
  for (let y = 0; y < cells; y++) for (let x = 0; x < cells; x++) {
    const sum = [0, 0, 0]; let n = 0;
    const x0 = Math.floor(x * png.width / cells), x1 = Math.max(x0 + 1, Math.floor((x + 1) * png.width / cells));
    const y0 = Math.floor(y * png.height / cells), y1 = Math.max(y0 + 1, Math.floor((y + 1) * png.height / cells));
    for (let j = y0; j < Math.min(y1, png.height); j++) for (let i = x0; i < Math.min(x1, png.width); i++) {
      const p = (j * png.width + i) * 4; n++;
      for (let k = 0; k < 3; k++) sum[k] += png.data[p + k];
    }
    data.push(...sum.map(v => Math.round(v / n / 8)));
  }
  assert(new Set(data).size > 6, 'Screenshot has insufficient color variation');
  return { width: png.width, height: png.height, data: deflateSync(Buffer.from(data)).toString('base64') };
}
async function capture(page, key, locator) {
  const path = resolve(output, key + '.png');
  const buffer = await (locator ? locator.screenshot({ path, animations: 'disabled' }) : page.screenshot({ path, animations: 'disabled' }));
  const actual = signature(buffer); snapshots[key] = actual;
  if (!baseline) return;
  const expected = baseline.snapshots[key];
  assert(expected, `Missing reference: ${key}`);
  const a = inflateSync(Buffer.from(actual.data, 'base64')), b = inflateSync(Buffer.from(expected.data, 'base64'));
  const distance = a.reduce((sum, v, i) => sum + Math.abs(v - b[i]), 0) / a.length / 31.875;
  assert(Math.abs(actual.width - expected.width) <= 2 && Math.abs(actual.height - expected.height) <= 12 && distance <= 0.035,
    `${key}: visual regression (dimensions ${actual.width}x${actual.height} vs ${expected.width}x${expected.height}, distance ${distance.toFixed(4)})`);
}
async function noOverflow(page, label) {
  const result = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    width: Math.max(document.body.scrollWidth, document.documentElement.scrollWidth),
    offenders: [...document.querySelectorAll('main *')].filter(el => el.getBoundingClientRect().right > innerWidth + 3).slice(0, 8).map(el => el.className)
  }));
  assert(result.width <= result.viewport + 2, `${label}: horizontal overflow ${JSON.stringify(result)}`);
}
async function scan(page, key) {
  const axe = await new AxeBuilder({ page }).withTags(wcag).analyze();
  const violations = axe.violations.map(v => ({ id: v.id, impact: v.impact, nodes: v.nodes.map(n => ({ target: n.target, summary: n.failureSummary })) }));
  await writeFile(resolve(output, key + '-axe.json'), JSON.stringify(violations, null, 2));
  assert.equal(violations.length, 0, `${key}: axe violations: ${JSON.stringify(violations)}`);
}
async function openCatalog(page, profile) {
  const target = authenticated ? '/dev/ui?quality=1' : `/catalog-${profile.locale}.html`;
  const response = await page.goto(new URL(target, base).href, { waitUntil: 'load' });
  assert.equal(response?.status(), 200);
  await page.locator('#cuw-quality-catalog').waitFor();
  if (authenticated && await page.locator('html').getAttribute('lang') !== profile.locale) {
    await page.locator('#quality-locale').selectOption(profile.locale);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('#quality-locale').locator('..').getByRole('button').click()]);
  }
  assert.equal(await page.locator('html').getAttribute('lang'), profile.locale);
  // Wait for existing Stimulus appearance controller instead of manipulating DOM state.
  await page.locator('[data-style="light"][aria-pressed="true"]').waitFor();
  await page.locator(`[data-style="${profile.theme}"]`).click();
  await page.locator(`[data-density="${profile.density}"]`).click();
  assert.equal(await page.locator('html').getAttribute('data-cos-theme'), profile.theme);
  assert.equal(await page.locator('html').getAttribute('data-cos-density'), profile.density);
  await page.evaluate(() => document.fonts.ready);
}
try {
  for (const profile of profiles) {
    const mobile = profile.viewport.width === 390;
    const context = await browser.newContext({ viewport: profile.viewport, deviceScaleFactor: 1, isMobile: mobile, hasTouch: mobile,
      reducedMotion: 'reduce', colorScheme: profile.scheme, locale: profile.locale, serviceWorkers: 'block',
      ...(authenticated ? { storageState: process.env.CUW_QUALITY_STORAGE_STATE } : {}) });
    try {
      const page = await context.newPage();
      const runtimeErrors = [];
      page.on('pageerror', e => runtimeErrors.push(e.message));
      await openCatalog(page, profile);
      await noOverflow(page, profile.name);
      assert.equal(await page.locator('#cuw-quality-states [data-cos-outcome-state]').count(), 16);
      const unknown = page.locator('#cuw-quality-states [data-cos-outcome-state="external_outcome_unknown"]');
      assert(await unknown.isVisible());
      assert.equal(await unknown.locator('strong').innerText(), profile.locale === 'uk' ? 'Зовнішній результат невідомий' : 'External outcome unknown');
      assert.equal(await page.locator('#quality-grid .cos-data-grid__search .cos-field__label').innerText(), profile.locale === 'uk' ? 'Пошук' : 'Search');
      assert(!(await unknown.getAttribute('class')).includes('--positive'));
      assert.equal(await page.locator('#quality-denied-grid').locator('form, table, article, button, a').count(), 0);
      assert.equal(await page.locator('#quality-grid .cos-data-grid__table-wrap').isVisible(), !mobile);
      assert.equal(await page.locator('#quality-grid .cos-data-grid__mobile').isVisible(), mobile);
      const invalid = page.locator('#quality-amount');
      assert.equal(await invalid.getAttribute('aria-invalid'), 'true');
      assert.equal(await page.locator('#quality-amount-errors').count(), 1);
      if (mobile) assert((await invalid.boundingBox()).height >= 44);
      const spinnerMotion = await page.locator('#cuw-quality-states .cos-spinner').first().evaluate(el => getComputedStyle(el).animationName);
      assert.equal(spinnerMotion, 'none');
      const color = await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme);
      assert.equal(color, profile.theme === 'system' ? profile.scheme : profile.theme);

      await scan(page, profile.name + '-catalog');
      await capture(page, profile.name + '-states', page.locator('#cuw-quality-states'));
      await capture(page, profile.name + '-entity', page.locator('#cuw-quality-entity'));
      await capture(page, profile.name + '-grid', mobile ? page.locator('#quality-grid .cos-data-grid__card').first() : page.locator('#quality-grid .cos-data-grid__table-wrap'));
      await capture(page, profile.name + '-form', page.locator('#cuw-quality-form'));

      const tabs = page.locator('#quality-modes');
      const result = tabs.locator('#quality-modes-tab-result');
      await result.focus();
      const ref = await tabs.locator('[role=tabpanel]:visible [data-quality-reference]').getAttribute('data-quality-reference');
      for (const mode of ['result','process','expert']) {
        if (mode !== 'result') await page.keyboard.press('ArrowRight');
        assert.equal(await tabs.locator(`#quality-modes-tab-${mode}`).getAttribute('aria-selected'), 'true');
        assert.equal(await tabs.locator('[role=tabpanel]:visible [data-quality-reference]').getAttribute('data-quality-reference'), ref);
        assert(await tabs.locator('[role=tabpanel]:visible [data-cos-outcome-state="external_outcome_unknown"]').isVisible());
        await capture(page, profile.name + '-' + mode, tabs);
      }
      const trigger = page.locator('[data-dialog-trigger-dialog-id-value="quality-confirm"]');
      await trigger.focus(); await page.keyboard.press('Enter');
      const dialog = page.locator('#quality-confirm');
      assert(await dialog.isVisible());
      assert.equal(await page.evaluate(() => document.activeElement?.hasAttribute('autofocus')), true);
      const input = dialog.locator('input');
      const confirm = dialog.locator('[data-confirm-target="confirmButton"]');
      assert(await confirm.isDisabled());
      await input.fill('CONFIRM'); assert(await confirm.isEnabled());
      await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' });
      assert.equal(await trigger.evaluate(el => el === document.activeElement), true);
      await trigger.click(); assert(await confirm.isDisabled()); assert.equal(await input.inputValue(), '');
      await scan(page, profile.name + '-approval');
      await capture(page, profile.name + '-approval', dialog);
      await page.keyboard.press('Escape');
      for (const id of ['quality-modal','quality-drawer']) {
        const button = page.locator(`[data-dialog-trigger-dialog-id-value="${id}"]`);
        await button.focus(); await page.keyboard.press('Enter');
        await page.locator('#' + id).waitFor({ state:'visible' });
        await page.keyboard.press('Escape');
        await page.locator('#' + id).waitFor({ state:'hidden' });
        assert(await button.evaluate(el => el === document.activeElement));
      }
      assert.deepEqual(runtimeErrors, []);
      const metrics = await page.evaluate(() => {
        const entries = performance.getEntriesByType('resource').filter(e => new URL(e.name).origin === location.origin);
        const nav = performance.getEntriesByType('navigation')[0];
        const bytes = arr => arr.reduce((sum, e) => sum + e.transferSize, 0);
        return { domNodes: document.querySelectorAll('*').length, resources: entries.filter(e => e.transferSize > 0).length, transferBytes: bytes(entries) + nav.transferSize,
          jsBytes: bytes(entries.filter(e => e.name.endsWith('.js'))), decodedJsBytes: entries.filter(e => e.name.endsWith('.js')).reduce((sum,e) => sum + e.decodedBodySize,0), cssBytes: bytes(entries.filter(e => e.name.endsWith('.css'))),
          ttfbMs: Math.round(nav.responseStart), navigationMs: Math.round(nav.duration) };
      });
      for (const [key, limit] of Object.entries(budgets)) assert(metrics[key] <= limit, `${key} ${metrics[key]} exceeds ${limit}`);

      // Preview uses the real existing Shell template; static fixture, not a tenant/runtime test.
      const shellPath = authenticated ? '/dev/shell' : `/shell-${profile.locale}.html`;
      const shellResponse = await page.goto(new URL(shellPath, base).href, { waitUntil: 'load' });
      assert.equal(shellResponse?.status(), 200);
      await page.locator('.cos-shell').waitFor();
      await page.evaluate(({theme,density}) => { document.documentElement.dataset.cosTheme = theme; document.documentElement.dataset.cosDensity = density; }, profile);
      await noOverflow(page, profile.name + ' shell');
      if (mobile) assert(await page.locator('.cos-shell__mobile-nav').isVisible());
      await capture(page, profile.name + '-shell');
      const shellAxe = await new AxeBuilder({ page }).withTags(wcag).analyze();
      const overlaps = await page.locator('.cos-shell__topbar').evaluate(topbar => {
        const controls = [...topbar.querySelectorAll('button, select, a[href]')].filter(el => el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
        const hits = [];
        for (let i=0; i<controls.length; i++) for (let j=i+1; j<controls.length; j++) {
          const a=controls[i].getBoundingClientRect(), b=controls[j].getBoundingClientRect();
          if (Math.min(a.right,b.right)-Math.max(a.left,b.left)>3 && Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>3) hits.push([controls[i].className,controls[j].className]);
        }
        return hits;
      });
      const shellFinding = { profile: profile.name, overlaps, axe: shellAxe.violations.map(v => ({id:v.id, nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})) };
      shellFindings.push(shellFinding);
      await writeFile(resolve(output, profile.name + '-shell-axe.json'), JSON.stringify(shellFinding, null, 2));
      if (process.env.CUW_QUALITY_REQUIRE_SHELL === '1') assert(overlaps.length === 0 && shellFinding.axe.length === 0, JSON.stringify(shellFinding));
      results.push({ profile: profile.name, metrics, passed: true });
      console.log('PASS', profile.name);
    } catch (e) {
      failures.push({ profile: profile.name, error: e.message });
      console.error('FAIL', profile.name, e.message);
    } finally { await context.close(); }
  }
} finally {
  await browser.close();
  if (server) await new Promise(r => server.close(r));
  await writeFile(resolve(output, 'report.json'), JSON.stringify({ ok: failures.length === 0, scope: authenticated ? 'authenticated dev previews' : 'synthetic Twig fixtures', shellAcceptance: shellFindings.every(f => !f.overlaps.length && !f.axe.length), shellFindings, browser: browser.version(), budgets, results, failures }, null, 2));
}
if (failures.length) process.exitCode = 1;
else if (update) {
  await mkdir(resolve('tests/browser/baselines'), { recursive: true });
  await writeFile(baselineFile, JSON.stringify({ version: 1, browser: browser.version(), method: '40x40 mean RGB quantized / 8, deflate/base64; distance <= 0.035; dimensions tolerance 2x12', snapshots }, null, 2) + '\n');
}
