import { chromium } from 'playwright-core';

const baseUrl = process.env.COS_WEB_SMOKE_BASE_URL || 'http://127.0.0.1:8081';
const email = process.env.COS_WEB_SMOKE_EMAIL || '';
const password = process.env.COS_WEB_SMOKE_PASSWORD || '';
const executablePath = process.env.CHROME_PATH || '/usr/bin/google-chrome';

if (!email || !password) {
  throw new Error('COS_WEB_SMOKE_EMAIL and COS_WEB_SMOKE_PASSWORD are required.');
}

const browser = await chromium.launch({ headless: true, executablePath });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();

const requests = [];
page.on('response', (response) => {
  const url = response.url();
  if (url.includes('/diagnostics/permissions')) {
    requests.push({ url, status: response.status() });
  }
});

async function expect200(path, marker = null) {
  const response = await page.goto(new URL(path, baseUrl).toString(), {
    waitUntil: 'domcontentloaded',
    timeout: 25000,
  });
  const status = response?.status() ?? 0;
  if (status !== 200) {
    throw new Error(path + ' expected HTTP 200, received ' + status);
  }
  if (marker && await page.locator(marker).count() === 0) {
    const finalUrl = page.url();
    const title = await page.title().catch(() => '');
    const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 1200).replace(/\s+/g, ' ');
    throw new Error(path + ' is missing expected UI marker ' + marker + '; final=' + finalUrl + '; title=' + title + '; body=' + body);
  }
}

try {
  await expect200('/auth/login', 'form[action="/auth/login"]');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    page.locator('button[type="submit"]').click(),
  ]);

  await expect200('/cos', '[data-cos-public="cos-landing"]');
  await expect200('/cos/en', '[data-cos-public="cos-landing"]');
  await expect200('/cos/en/domains/sales', '[data-cos-public="cos-domain"]');

  await expect200('/cabinet', '[data-cos-portal="cabinet"]');
  await expect200('/workspace/ai', '[data-cos-ai-center]');
  await expect200('/admin/engineering', '[data-cos-engineering="index"]');

  for (const path of [
    '/growth',
    '/growth/candidates',
    '/growth/accounts',
    '/growth/signals',
    '/growth/collectors',
    '/growth/learning',
    '/growth/experiments',
    '/growth/market',
    '/growth/settings',
  ]) {
    await expect200(path, '.cos-growth-workspace');
  }

  await expect200('/sales/pipeline', '[data-sales-pipeline-root]');
  await expect200('/sales/director', '[data-cos-archetype]');
  await expect200('/sales/admin/agents', '[data-cos-archetype]');
  await expect200('/spatial/edit', 'form[action^="/spatial/save"]');

  requests.length = 0;
  await expect200('/admin/diagnostics/methodology-studio', '[data-studio]');
  await page.waitForTimeout(750);

  const legacy = requests.filter((item) => new URL(item.url).pathname.startsWith('/api/admin/diagnostics/'));
  if (legacy.length > 0) {
    throw new Error('Methodology Studio still requests retired API routes: ' + JSON.stringify(legacy));
  }
  const canonical = requests.find((item) => new URL(item.url).pathname === '/api/v1/admin/diagnostics/permissions');
  if (!canonical || canonical.status !== 200) {
    throw new Error('Methodology Studio canonical permissions request did not return HTTP 200: ' + JSON.stringify(requests));
  }

  console.log('Production cutover Web route smoke passed.');
} finally {
  await context.close();
  await browser.close();
}
