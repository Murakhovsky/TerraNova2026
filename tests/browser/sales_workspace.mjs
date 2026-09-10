import { chromium } from 'playwright-core';

// EPIC 2 browser contract. This suite intentionally mutates Sales state and must
// run only against a disposable, mutation-safe fixture environment.
const baseUrl = process.argv[2] || process.env.SALES_E2E_BASE_URL || '';
const storageState = process.argv[3] || process.env.SALES_E2E_STORAGE_STATE || '';
const executablePath = process.env.CHROME_PATH || '';
const mutationSafe = process.env.SALES_E2E_MUTATION_SAFE === '1';

if (!baseUrl) throw new Error('SALES_E2E_BASE_URL is required. Browser E2E must not silently SKIP.');
if (!storageState) throw new Error('SALES_E2E_STORAGE_STATE is required for authenticated Sales E2E.');
if (!mutationSafe) throw new Error('Set SALES_E2E_MUTATION_SAFE=1 only for a disposable Sales fixture environment.');

const browser = await chromium.launch({
  headless: true,
  ...(executablePath ? { executablePath } : {}),
});

const absolute = (path) => new URL(path, baseUrl).toString();
const assertOk = (response, label) => {
  if (!response || response.status() >= 400) throw new Error(`${label} returned ${response?.status() ?? 'no response'}`);
};
const waitMutation = (page, fragment, action, label) => Promise.all([
  page.waitForResponse((response) => response.url().includes(fragment) && response.request().method() === 'POST'),
  action(),
]).then(([response]) => {
  assertOk(response, label);
  return response;
});

try {
  for (const profile of [
    { name: 'desktop', viewport: { width: 1440, height: 1000 }, mobile: false },
    { name: 'mobile', viewport: { width: 390, height: 844 }, mobile: true },
  ]) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.mobile, storageState });
    const page = await context.newPage();
    for (const path of ['/sales/today', '/sales/leads', '/sales/pipeline']) {
      const response = await page.goto(absolute(path), { waitUntil: 'networkidle' });
      assertOk(response, `${profile.name}: ${path}`);
      await page.locator('[data-sales-workspace]').waitFor({ state: 'visible' });
      await page.locator('[data-sales-global-search]').waitFor({ state: 'visible' });
    }
    const input = page.locator('[data-sales-global-search-input]').first();
    await input.fill('test');
    const searchResponse = await page.waitForResponse((response) => response.url().includes('/api/sales/search'));
    assertOk(searchResponse, `${profile.name}: Sales search`);
    await page.locator('[data-sales-global-search-results]:not([hidden])').waitFor({ state: 'visible' });
    await context.close();
  }

  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState });
  const page = await context.newPage();

  assertOk(await page.goto(absolute('/sales/leads?status=new'), { waitUntil: 'networkidle' }), 'Lead Inbox');
  const leadQualify = page.locator('[data-lead-id] [data-sales-lead-status][data-status="qualified"]').first();
  if (!await leadQualify.count()) throw new Error('Mutation fixture requires at least one NEW lead.');
  await waitMutation(page, '/api/sales/leads/', () => leadQualify.click(), 'Qualify Lead');
  await page.waitForLoadState('networkidle').catch(() => {});

  assertOk(await page.goto(absolute('/sales/today'), { waitUntil: 'networkidle' }), 'Sales Today');
  const complete = page.locator('[data-sales-activity-complete]').first();
  if (!await complete.count()) throw new Error('Mutation fixture requires at least one completable Sales activity.');
  await waitMutation(page, '/activities/', () => complete.click(), 'Complete activity');

  const approval = page.locator('[data-sales-approval] [data-decision="approve"]').first();
  if (!await approval.count()) throw new Error('Mutation fixture requires at least one pending approval assigned to the E2E user.');
  await waitMutation(page, '/api/sales/approvals/', () => approval.click(), 'Approve COS action');

  assertOk(await page.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' }), 'Sales Pipeline');
  const card = page.locator('[data-sales-deal-card]').first();
  if (!await card.count()) throw new Error('Mutation fixture requires at least one Deal card.');
  const sourceStage = await card.getAttribute('data-stage-id');
  const zones = page.locator('[data-sales-stage-dropzone]');
  let targetZone = null;
  for (let index = 0; index < await zones.count(); index += 1) {
    const zone = zones.nth(index);
    if ((await zone.getAttribute('data-stage-id')) !== sourceStage) { targetZone = zone; break; }
  }
  if (!targetZone) throw new Error('Mutation fixture requires at least two Pipeline stages.');
  await waitMutation(page, '/api/sales/deals/', () => card.dragTo(targetZone), 'Change Deal stage');
  await page.waitForLoadState('networkidle').catch(() => {});

  assertOk(await page.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' }), 'Sales Pipeline after stage change');
  const dealHref = await page.locator('[data-sales-deal-card]').first().getAttribute('href');
  if (!dealHref) throw new Error('Mutation fixture requires a Deal workspace link.');
  assertOk(await page.goto(absolute(dealHref), { waitUntil: 'networkidle' }), 'Deal workspace');
  await page.locator('[data-sales-deal-workspace]').waitFor({ state: 'visible' });

  const messageForm = page.locator('form[data-sales-operation-form][data-operation="message"]');
  await messageForm.locator('select[name="channel"]').selectOption('WEB');
  await messageForm.locator('textarea[name="body"]').fill(`Sales E2E ${Date.now()}`);
  await waitMutation(page, '/messages', () => messageForm.locator('button').filter({ hasText: 'Send Message' }).click(), 'Send Message');

  // The UI reloads after a successful message. Re-open the Deal explicitly so the
  // next assertion cannot race that reload.
  assertOk(await page.goto(absolute(dealHref), { waitUntil: 'networkidle' }), 'Deal workspace after message');

  await page.locator('#intelligence').waitFor({ state: 'visible' });
  const execute = page.locator('[data-sales-action] [data-decision="execute"]').first();
  await execute.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
  if (!await execute.count()) throw new Error('Mutation fixture requires at least one executable COS action for the Deal.');
  await waitMutation(page, '/api/sales/actions/', () => execute.click(), 'Execute COS action');

  await context.close();
  console.log(JSON.stringify({ ok: true, suite: 'Sales V0.6.7 mutation E2E' }, null, 2));
} finally {
  await browser.close();
}
