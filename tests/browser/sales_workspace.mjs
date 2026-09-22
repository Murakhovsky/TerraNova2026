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
const waitMutation = (page, fragment, action, label, method = 'POST') => Promise.all([
  page.waitForResponse((response) => response.url().includes(fragment) && response.request().method() === method),
  action(),
]).then(([response]) => {
  assertOk(response, label);
  return response;
});
const assertCount = async (locator, expected, label) => {
  const actual = await locator.count();
  if (actual !== expected) throw new Error(`${label}: expected ${expected}, got ${actual}`);
};

const salesPerformanceBudget = {
  navigationMs: 4500,
  transferBytes: 5 * 1024 * 1024,
  domNodes: 5000,
};

const assertPerformance = async (page, label) => {
  const metrics = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const resources = performance.getEntriesByType('resource');
    return {
      navigationMs: Math.round(nav?.duration || 0),
      transferBytes: Math.round(
        (nav?.transferSize || 0)
        + resources.reduce((total, item) => total + (item.transferSize || 0), 0),
      ),
      domNodes: document.querySelectorAll('*').length,
    };
  });

  for (const [metric, limit] of Object.entries(salesPerformanceBudget)) {
    if (metrics[metric] > limit) {
      throw new Error(`${label}: ${metric}=${metrics[metric]} exceeds Sales cutover budget ${limit}`);
    }
  }
};

try {
  for (const profile of [
    { name: 'desktop', viewport: { width: 1440, height: 1000 }, mobile: false },
    { name: 'mobile', viewport: { width: 390, height: 844 }, mobile: true },
  ]) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.mobile, storageState });
    const page = await context.newPage();

    for (const path of ['/sales/dashboard', '/sales/leads']) {
      const response = await page.goto(absolute(path), { waitUntil: 'networkidle' });
      assertOk(response, `${profile.name}: ${path}`);
      await page.locator('.cos-shell[data-controller="workspace-shell"]').waitFor({ state: 'visible' });
      await assertPerformance(page, `${profile.name}: ${path}`);
    }

    for (const path of ['/sales/today', '/sales/pipeline']) {
      const response = await page.goto(absolute(path), { waitUntil: 'networkidle' });
      assertOk(response, `${profile.name}: ${path}`);
      await page.locator('[data-sales-workspace]').waitFor({ state: 'visible' });
      await page.locator('[data-sales-global-search]').waitFor({ state: 'visible' });
    }

    const legacyInput = page.locator('[data-sales-global-search-input]').first();
    await legacyInput.fill('test');
    const searchResponse = page.waitForResponse((response) => response.url().includes('/api/v1/sales/search'));
    await legacyInput.dispatchEvent('input');
    assertOk(await searchResponse, `${profile.name}: Sales search`);
    await page.locator('[data-sales-global-search-results]:not([hidden])').waitFor({ state: 'visible' });

    const leadsResponse = await page.goto(absolute('/sales/leads'), { waitUntil: 'networkidle' });
    assertOk(leadsResponse, `${profile.name}: /sales/leads`);
    await page.locator('.cos-shell[data-controller="workspace-shell"]').waitFor({ state: 'visible' });
    await page.locator('[data-sales-surface="lead-list"]').waitFor({ state: 'visible' });
    await context.close();
  }

  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState });
  const page = await context.newPage();

  assertOk(await page.goto(absolute('/sales/leads?status=new'), { waitUntil: 'networkidle' }), 'Lead Inbox');
  const leadQualify = page.locator('[data-lead-id] [data-sales-lead-status][data-status="qualified"]').first();
  if (!await leadQualify.count()) throw new Error('Mutation fixture requires at least one NEW lead.');
  const leadCard = leadQualify.locator('xpath=ancestor::*[@data-lead-id][1]');
  const leadId = await leadCard.getAttribute('data-lead-id');
  if (!leadId) throw new Error('Qualify fixture lead must expose data-lead-id.');
  await waitMutation(page, `/api/v1/sales/leads/${leadId}`, () => leadQualify.click(), 'Qualify Lead', 'PATCH');
  assertOk(await page.goto(absolute('/sales/leads?status=qualified'), { waitUntil: 'networkidle' }), 'Qualified Lead postcondition');
  await assertCount(page.locator(`[data-lead-id="${leadId}"]`), 1, 'Qualified Lead must persist after reload');

  assertOk(await page.goto(absolute(`/sales/leads/${leadId}`), { waitUntil: 'networkidle' }), 'Lead Workspace');
  await page.locator('[data-sales-surface="lead-workspace"]').waitFor({ state: 'visible' });
  await assertPerformance(page, 'Lead Workspace');

  assertOk(await page.goto(absolute('/sales/today'), { waitUntil: 'networkidle' }), 'Sales Today');
  const complete = page.locator('[data-sales-activity-complete]').first();
  if (!await complete.count()) throw new Error('Mutation fixture requires at least one completable Sales activity.');
  const activityPanel = complete.locator('xpath=ancestor::*[@data-activity-id][1]');
  const activityId = await activityPanel.getAttribute('data-activity-id');
  if (!activityId) throw new Error('Completable activity must expose data-activity-id.');
  await waitMutation(page, '/activities/', () => complete.click(), 'Complete activity');
  assertOk(await page.reload({ waitUntil: 'networkidle' }), 'Completed activity postcondition');
  await assertCount(page.locator(`[data-activity-id="${activityId}"]`), 0, 'Completed activity must leave Today after reload');

  const approval = page.locator('[data-sales-approval] [data-decision="approve"]').first();
  if (!await approval.count()) throw new Error('Mutation fixture requires at least one pending approval assigned to the E2E user.');
  const approvalPanel = approval.locator('xpath=ancestor::*[@data-sales-approval][1]');
  const approvalId = await approvalPanel.getAttribute('data-approval-id');
  if (!approvalId) throw new Error('Pending approval must expose data-approval-id.');
  await waitMutation(page, `/api/v1/sales/approvals/${approvalId}/approve`, () => approval.click(), 'Approve COS action');
  assertOk(await page.reload({ waitUntil: 'networkidle' }), 'Approval postcondition');
  await assertCount(page.locator(`[data-sales-approval][data-approval-id="${approvalId}"]`), 0, 'Approved action must leave pending approvals after reload');

  assertOk(await page.goto(absolute('/sales/pipeline'), { waitUntil: 'networkidle' }), 'Sales Pipeline');
  const card = page.locator('[data-sales-deal-card]').first();
  if (!await card.count()) throw new Error('Mutation fixture requires at least one Deal card.');
  const dealId = await card.getAttribute('data-deal-id');
  const sourceStage = await card.getAttribute('data-stage-id');
  if (!dealId || !sourceStage) throw new Error('Deal card must expose deal and stage ids.');
  const zones = page.locator('[data-sales-stage-dropzone]');
  let targetZone = null;
  for (let index = 0; index < await zones.count(); index += 1) {
    const zone = zones.nth(index);
    if ((await zone.getAttribute('data-stage-id')) !== sourceStage) { targetZone = zone; break; }
  }
  if (!targetZone) throw new Error('Mutation fixture requires at least two Pipeline stages.');
  const targetStage = await targetZone.getAttribute('data-stage-id');
  if (!targetStage) throw new Error('Target Pipeline stage must expose data-stage-id.');
  await waitMutation(page, `/api/v1/sales/opportunities/${dealId}/stage`, () => card.dragTo(targetZone), 'Change Deal stage');
  assertOk(await page.reload({ waitUntil: 'networkidle' }), 'Deal stage postcondition');
  const movedCard = page.locator(`[data-sales-deal-card][data-deal-id="${dealId}"]`);
  await assertCount(movedCard, 1, 'Moved Deal must remain in Pipeline after reload');
  if ((await movedCard.getAttribute('data-stage-id')) !== targetStage) {
    throw new Error(`Deal ${dealId} did not persist target stage ${targetStage}.`);
  }

  const dealHref = await movedCard.getAttribute('href');
  if (!dealHref) throw new Error('Mutation fixture requires a Deal workspace link.');
  assertOk(await page.goto(absolute(dealHref), { waitUntil: 'networkidle' }), 'Deal workspace');
  await page.locator('[data-sales-deal-workspace]').waitFor({ state: 'visible' });

  const messageBody = `Sales E2E ${Date.now()}`;
  const messageForm = page.locator('form[data-sales-operation-form][data-operation="message"]');
  await messageForm.locator('select[name="channel"]').selectOption('WEB');
  await messageForm.locator('textarea[name="body"]').fill(messageBody);
  await waitMutation(page, `/api/v1/sales/opportunities/${dealId}/communications`, () => messageForm.locator('button').filter({ hasText: 'Send Message' }).click(), 'Send Message');
  assertOk(await page.goto(absolute(dealHref), { waitUntil: 'networkidle' }), 'Message postcondition');
  await assertCount(page.locator('.tn-sales-message').filter({ hasText: messageBody }), 1, 'Sent canonical WEB message must persist after reload');

  await page.locator('#intelligence').waitFor({ state: 'visible' });
  const execute = page.locator('[data-sales-action] [data-decision="execute"]').first();
  await execute.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
  if (!await execute.count()) throw new Error('Mutation fixture requires at least one executable COS action for the Deal.');
  const actionPanel = execute.locator('xpath=ancestor::*[@data-sales-action][1]');
  const actionId = await actionPanel.getAttribute('data-action-id');
  if (!actionId) throw new Error('Executable COS action must expose data-action-id.');
  await waitMutation(page, `/api/v1/sales/actions/${actionId}/execute`, () => execute.click(), 'Execute COS action');
  const intelligenceResponse = page.waitForResponse((response) => response.url().includes(`/api/v1/sales/opportunities/${dealId}/intelligence`) && response.request().method() === 'GET');
  assertOk(await page.goto(absolute(dealHref), { waitUntil: 'domcontentloaded' }), 'COS action postcondition');
  assertOk(await intelligenceResponse, 'Reloaded Deal intelligence');
  await page.locator('[data-sales-intelligence]').waitFor({ state: 'visible' });
  await assertCount(page.locator(`[data-sales-action][data-action-id="${actionId}"] [data-decision="execute"]`), 0, 'Executed COS action must not remain executable after reload');

  await context.close();
  console.log(JSON.stringify({ ok: true, suite: 'Sales V0.6.8 Epic 2 Closure mutation E2E' }, null, 2));
} finally {
  await browser.close();
}
