import { chromium } from 'playwright-core';
import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { resolve } from 'node:path';

const baseUrl = process.env.UI_AUDIT_BASE_URL || process.env.SALES_E2E_BASE_URL || 'https://company-os.shop';
const storageStatePath = process.env.UI_AUDIT_STORAGE_STATE || '';
const outputDir = resolve('tmp/ui-route-audit');
await mkdir(outputDir, { recursive: true });

function parseRoutes(yaml) {
  const routes = [];
  let current = null;
  for (const line of yaml.split(/\r?\n/)) {
    const top = line.match(/^([A-Za-z0-9_]+):\s*$/);
    if (top) {
      if (current) routes.push(current);
      current = { name: top[1], path: null, methods: [], controller: null };
      continue;
    }
    if (!current) continue;
    let m = line.match(/^\s+path:\s*(.+?)\s*$/);
    if (m) current.path = m[1].replace(/^['"]|['"]$/g, '');
    m = line.match(/^\s+methods:\s*\[(.*?)\]\s*$/);
    if (m) current.methods = m[1].split(',').map((x) => x.trim());
    m = line.match(/^\s+controller:\s*(.+?)\s*$/);
    if (m) current.controller = m[1].replace(/^['"]|['"]$/g, '');
  }
  if (current) routes.push(current);
  return routes.filter((r) =>
    r.path &&
    r.controller &&
    r.controller.startsWith('App\\Web\\') &&
    r.methods.some((m) => m === 'GET' || m === 'HEAD')
  );
}

const routes = parseRoutes(await readFile('symfony/config/routes.yaml', 'utf8'));
const isDynamic = (path) => /\{[^}]+\}/.test(path);

const publicPrefixes = [
  '/auth/', '/terra-nova', '/agency', '/services', '/partners', '/team',
  '/cases', '/vacancies', '/contacts', '/it', '/art', '/blog', '/guide/',
  '/property/catalog', '/property/favour', '/property/show/',
  '/property/presentation/', '/property/pdf/', '/property/type/',
  '/property/city/', '/nerukhomist/', '/property/submit',
  '/property/create', '/submit-property', '/cos/'
];

const privateExact = new Set([
  '/admin', '/cabinet', '/property/map', '/cos/control-center',
  '/cos/architecture', '/cos/architecture/graph', '/cos/architecture/health'
]);

function isPrivate(path) {
  if (privateExact.has(path)) return true;
  if (
    path.startsWith('/workspace/') ||
    path.startsWith('/admin/') ||
    path.startsWith('/growth') ||
    path.startsWith('/sales') ||
    path.startsWith('/client-case') ||
    path.startsWith('/cabinet/') ||
    path.startsWith('/diagnostics/') ||
    path.startsWith('/spatial/') ||
    path.startsWith('/property/manage') ||
    path.startsWith('/property/listing') ||
    path.startsWith('/property/submissions') ||
    path.startsWith('/property/submission') ||
    path.startsWith('/dev/')
  ) return true;
  if (path === '/') return false;
  return !publicPrefixes.some((prefix) => path.startsWith(prefix));
}

const candidates = [
  process.env.CHROME_PATH,
  process.env.BROWSER_EXECUTABLE,
  '/usr/bin/google-chrome',
  '/usr/bin/chromium'
].filter(Boolean);

let executablePath = null;
for (const candidate of candidates) {
  try {
    await access(candidate, fsConstants.X_OK);
    executablePath = candidate;
    break;
  } catch {}
}
if (!executablePath) throw new Error('Chrome/Chromium not found');

let authState = false;
if (storageStatePath) {
  authState = await access(storageStatePath, fsConstants.R_OK).then(() => true).catch(() => false);
}

const browser = await chromium.launch({ headless: true, executablePath });
const publicContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const privateContext = authState
  ? await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState: storageStatePath })
  : null;

const hrefs = new Set();
const results = [];
const absolute = (path) => new URL(path, baseUrl).toString();

function escapeRegex(value) {
  return value.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&');
}

function templateRegex(template) {
  const parts = template.split('/').map((part) =>
    /^\{[^}]+\}$/.test(part) ? '[^/?#]+' : escapeRegex(part)
  );
  return new RegExp('^' + parts.join('/') + '$');
}

function expected(path) {
  const caps = ['heading', 'content'];
  if (path === '/workspace/search') caps.push('search');
  if (path === '/workspace/ai') caps.push('input', 'action');
  if (path === '/auth/login' || path === '/auth/register') caps.push('form', 'input', 'action');
  if (path === '/property/catalog') caps.push('collection', 'search-or-filter');
  if (['/property/submit', '/property/create', '/submit-property'].includes(path)) caps.push('form', 'input', 'action');
  if (['/sales/leads', '/sales/deals'].includes(path)) caps.push('collection', 'search-or-filter');
  if (path === '/sales/pipeline') caps.push('collection');
  if (['/growth/candidates', '/growth/accounts', '/growth/signals', '/growth/experiments'].includes(path)) caps.push('collection');
  if (['/admin/users', '/admin/content', '/property/manage', '/property/listing', '/property/submissions'].includes(path)) caps.push('collection');
  if (path === '/cos/control-center') caps.push('collection', 'navigation');
  if (path === '/spatial/edit') caps.push('form', 'input', 'action');
  return [...new Set(caps)];
}

function capabilityOk(cap, dom) {
  if (!dom) return false;
  if (cap === 'heading') return dom.headings > 0;
  if (cap === 'content') return dom.textLength > 80;
  if (cap === 'search') return dom.search > 0 || dom.inputs > 0;
  if (cap === 'input') return dom.inputs > 0;
  if (cap === 'action') return dom.buttons > 0 || dom.links > 0;
  if (cap === 'form') return dom.forms > 0;
  if (cap === 'collection') return dom.tables > 0 || dom.cards > 0 || dom.emptyStates > 0;
  if (cap === 'search-or-filter') return dom.search > 0 || dom.filters > 0 || dom.inputs > 0;
  if (cap === 'navigation') return dom.tabs > 0 || dom.links > 8;
  return true;
}

async function domSnapshot(page) {
  return page.evaluate(() => {
    const count = (selector) => document.querySelectorAll(selector).length;
    const textLength = (document.body?.innerText || '').trim().length;
    return {
      title: document.title || '',
      headings: count('h1, h2'),
      forms: count('form'),
      inputs: count('input:not([type="hidden"]), textarea, select'),
      buttons: count('button, input[type="submit"], input[type="button"], [role="button"]'),
      links: count('a[href]'),
      tables: count('table, [role="table"], [data-controller*="data-grid"], .cos-data-grid'),
      cards: count('.card, .cos-card, [data-card], [class*="card"]'),
      tabs: count('[role="tab"], [role="tablist"], [data-controller*="tabs"]'),
      search: count('input[type="search"], [role="search"], [data-controller*="search"]'),
      filters: count('[data-controller*="filter"], .filter, .filters, [class*="filter"]'),
      emptyStates: count('.cos-empty-state, .empty-state, [data-empty-state]'),
      errorStates: count('.cos-error, .alert-danger, [data-error-state], [role="alert"]'),
      charts: count('canvas, [data-controller*="chart"], .chart'),
      maps: count('[data-controller*="map"], .map, [class*="map-"]'),
      textLength,
      lang: document.documentElement.lang || '',
      hrefs: [...document.querySelectorAll('a[href]')]
        .map((a) => a.getAttribute('href'))
        .filter((href) => href && href.startsWith('/'))
        .slice(0, 500)
    };
  });
}

async function inspect(route, actualPath) {
  const privateRoute = isPrivate(route.path);
  const context = privateRoute ? privateContext : publicContext;

  if (!context) {
    return {
      name: route.name, template: route.path, path: actualPath, private: privateRoute,
      rawStatus: null, finalStatus: null, finalPath: null, access: 'NO_AUTH_STATE',
      missing: ['authenticated-browser-state'], browserErrors: []
    };
  }

  const url = absolute(actualPath);
  let rawStatus = null;
  let location = null;
  let finalStatus = null;
  let finalPath = null;
  let dom = null;
  const errors = [];

  try {
    const raw = await context.request.get(url, {
      maxRedirects: 0, failOnStatusCode: false, timeout: 20000
    });
    rawStatus = raw.status();
    location = raw.headers()['location'] || null;
  } catch (error) {
    errors.push('request: ' + error.message);
  }

  if (route.path === '/sitemap.xml' || route.path === '/robots.txt' || route.path === '/dev/ui/data-export') {
    return {
      name: route.name, template: route.path, path: actualPath, private: privateRoute,
      rawStatus, finalStatus: null, finalPath: null,
      access: rawStatus === 200 ? 'OK' : 'HTTP_' + (rawStatus ?? 'ERROR'),
      missing: [], browserErrors: errors
    };
  }

  const page = await context.newPage();
  page.on('pageerror', (error) => errors.push('pageerror: ' + error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push('console: ' + message.text());
  });

  try {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });
    finalStatus = response?.status() ?? null;
    finalPath = new URL(page.url()).pathname;
    dom = await domSnapshot(page);
    for (const href of dom.hrefs) hrefs.add(href);
  } catch (error) {
    errors.push('goto: ' + error.message);
  }
  await page.close();

  const caps = expected(route.path);
  const missing = caps.filter((cap) => !capabilityOk(cap, dom));

  let access = 'OK';
  if (rawStatus === null) access = 'REQUEST_ERROR';
  else if (rawStatus >= 400) access = 'HTTP_' + rawStatus;
  else if (rawStatus >= 300 && rawStatus < 400) access = 'REDIRECT_' + rawStatus;
  if (finalStatus && finalStatus >= 400) access = 'PAGE_HTTP_' + finalStatus;
  if (finalPath === '/auth/login' && route.path !== '/auth/login') access = 'AUTH_REDIRECT';
  if (route.path === '/auth/logout' && rawStatus >= 300 && rawStatus < 400) access = 'EXPECTED_REDIRECT';

  return {
    name: route.name, template: route.path, path: actualPath, private: privateRoute,
    rawStatus, location, finalStatus, finalPath, access, expected: caps, missing,
    dom: dom ? {
      titlePresent: Boolean(dom.title.trim()), headings: dom.headings, forms: dom.forms,
      inputs: dom.inputs, buttons: dom.buttons, links: dom.links, tables: dom.tables,
      cards: dom.cards, tabs: dom.tabs, search: dom.search, filters: dom.filters,
      emptyStates: dom.emptyStates, errorStates: dom.errorStates, charts: dom.charts,
      maps: dom.maps, textLength: dom.textLength, lang: dom.lang
    } : null,
    browserErrors: [...new Set(errors)].slice(0, 20)
  };
}

try {
  for (const route of routes.filter((r) => !isDynamic(r.path))) {
    results.push(await inspect(route, route.path));
  }

  for (const route of routes.filter((r) => isDynamic(r.path))) {
    const rx = templateRegex(route.path);
    const candidate = [...hrefs]
      .map((href) => {
        try { return new URL(href, baseUrl).pathname; } catch { return href; }
      })
      .find((path) => rx.test(path));

    if (!candidate) {
      results.push({
        name: route.name, template: route.path, path: null, private: isPrivate(route.path),
        rawStatus: null, finalStatus: null, finalPath: null, access: 'UNRESOLVED_DYNAMIC',
        expected: expected(route.path), missing: ['valid-runtime-identifier'], browserErrors: []
      });
      continue;
    }
    results.push(await inspect(route, candidate));
  }

  const summary = {
    baseUrl,
    generatedAt: new Date().toISOString(),
    authState,
    routeCount: routes.length,
    checked: results.filter((r) => r.access !== 'UNRESOLVED_DYNAMIC').length,
    http200: results.filter((r) => r.rawStatus === 200).length,
    ok: results.filter((r) => ['OK', 'EXPECTED_REDIRECT'].includes(r.access)).length,
    failures: results.filter((r) => !['OK', 'EXPECTED_REDIRECT', 'UNRESOLVED_DYNAMIC'].includes(r.access)).length,
    unresolvedDynamic: results.filter((r) => r.access === 'UNRESOLVED_DYNAMIC').length,
    missingCapabilityPages: results.filter((r) => r.missing && r.missing.length > 0).length,
    browserErrorPages: results.filter((r) => r.browserErrors && r.browserErrors.length > 0).length
  };

  await writeFile(resolve(outputDir, 'ui-route-audit.json'), JSON.stringify({ summary, results }, null, 2));

  const md = [
    '# COS live UI route audit', '',
    '| Route | Actual | HTTP | Final | Access | Missing | Errors |',
    '|---|---|---:|---:|---|---|---:|',
    ...results.map((r) =>
      '| ' + r.template + ' | ' + (r.path || '—') + ' | ' +
      (r.rawStatus ?? '—') + ' | ' + (r.finalStatus ?? '—') + ' | ' +
      r.access + ' | ' + ((r.missing || []).join(', ') || '—') + ' | ' +
      (r.browserErrors?.length || 0) + ' |'
    ),
    '', '## Summary', '', JSON.stringify(summary, null, 2)
  ].join('\n');

  await writeFile(resolve(outputDir, 'ui-route-audit.md'), md);

  console.log(JSON.stringify(summary, null, 2));
  for (const r of results) {
    console.log(JSON.stringify({
      route: r.template, actual: r.path, http: r.rawStatus, final: r.finalStatus,
      access: r.access, missing: r.missing, errors: r.browserErrors?.length || 0
    }));
  }
} finally {
  await publicContext.close();
  if (privateContext) await privateContext.close();
  await browser.close();
}
