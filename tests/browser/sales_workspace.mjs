import { chromium } from 'playwright-core';

// Mutation-free authenticated smoke: it verifies workspace navigation/search only.
// Provide SALES_E2E_BASE_URL and, when login is required, SALES_E2E_STORAGE_STATE.
const baseUrl = process.argv[2] || process.env.SALES_E2E_BASE_URL || '';
const storageState = process.argv[3] || process.env.SALES_E2E_STORAGE_STATE || '';
const executablePath = process.env.CHROME_PATH || '';

if (!baseUrl) {
  console.log('SKIP Sales browser smoke: SALES_E2E_BASE_URL is not configured.');
  process.exit(0);
}

const browser = await chromium.launch({
  headless: true,
  ...(executablePath ? { executablePath } : {}),
});

const paths = ['/sales/today', '/sales/leads', '/sales/pipeline'];
const results = [];
try {
  for (const profile of [
    { name: 'desktop', viewport: { width: 1440, height: 1000 }, mobile: false },
    { name: 'mobile', viewport: { width: 390, height: 844 }, mobile: true },
  ]) {
    const context = await browser.newContext({
      viewport: profile.viewport,
      isMobile: profile.mobile,
      ...(storageState ? { storageState } : {}),
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('console', (message) => {
      if (message.type() === 'error') errors.push(`console: ${message.text()}`);
    });

    for (const path of paths) {
      const response = await page.goto(new URL(path, baseUrl).toString(), { waitUntil: 'networkidle' });
      if (!response || response.status() >= 400) throw new Error(`${profile.name}: ${path} returned ${response?.status() ?? 'no response'}`);
      await page.locator('[data-sales-workspace]').waitFor({ state: 'visible' });
      await page.locator('[data-sales-global-search]').waitFor({ state: 'visible' });
    }

    // Exercise the read-only search request and dropdown; no Sales state is mutated.
    const input = page.locator('[data-sales-global-search-input]').first();
    await input.fill('test');
    const searchResponse = await page.waitForResponse((response) => response.url().includes('/api/sales/search'));
    if (searchResponse.status() >= 400) throw new Error(`${profile.name}: Sales search returned ${searchResponse.status()}`);
    await page.locator('[data-sales-global-search-results]:not([hidden])').waitFor({ state: 'visible' });

    // When the Pipeline has data, verify the Deal workspace surface without clicking any mutation control.
    const firstDeal = page.locator('[data-sales-deal-card]').first();
    if (await firstDeal.count()) {
      const href = await firstDeal.getAttribute('href');
      if (href) {
        const response = await page.goto(new URL(href, baseUrl).toString(), { waitUntil: 'networkidle' });
        if (!response || response.status() >= 400) throw new Error(`${profile.name}: Deal workspace failed.`);
        await page.locator('[data-sales-deal-workspace]').waitFor({ state: 'visible' });
        await page.locator('#communications').waitFor({ state: 'visible' });
        await page.locator('#intelligence').waitFor({ state: 'visible' });
      }
    }

    results.push({ profile: profile.name, errors });
    await context.close();
  }
} finally {
  await browser.close();
}

const failures = results.flatMap((result) => result.errors);
if (failures.length) throw new Error(failures.join('\n'));
console.log(JSON.stringify(results, null, 2));
