import { chromium } from 'playwright-core';

const baseUrl = process.env.COS_WEB_SMOKE_BASE_URL || '';
const email = process.env.COS_CM_RESTRICTED_EMAIL || '';
const password = process.env.COS_CM_RESTRICTED_PASSWORD || '';
const executablePath = process.env.CHROME_PATH || '';
if (!baseUrl || !email || !password) {
  throw new Error('CM restricted-principal acceptance requires base URL and limited-rights credentials.');
}

const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
const absolute = (path) => new URL(path, baseUrl).toString();
const requireStatus = async (response, status, path) => {
  if (!response || response.status() !== status) {
    throw new Error(path + ': expected HTTP ' + status + ', got ' + (response?.status() ?? 'no response'));
  }
};

try {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  await requireStatus(await page.goto(absolute('/auth/login'), { waitUntil: 'domcontentloaded' }), 200, 'login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    page.locator('button[type="submit"]').click(),
  ]);

  await requireStatus(await page.goto(absolute('/capital-markets'), { waitUntil: 'domcontentloaded' }), 200, 'overview');
  const overview = page.locator('[data-cm-decision-workspace="overview"]');
  await overview.waitFor({ state: 'visible' });
  const overviewText = await overview.innerText();
  if (!overviewText.includes('Risk RESTRICTED')) {
    throw new Error('Generic View leaked canonical risk state or hid its restriction notice.');
  }
  if (!overviewText.includes('Portfolio Equity') || !overviewText.includes('Capital Map')) {
    throw new Error('Generic View lost the safe Decision Workspace shell.');
  }
  if (await overview.locator('a[href^="/capital-markets/opportunities/"]').count()) {
    throw new Error('Generic View leaked portfolio-aware opportunity detail links.');
  }

  for (const route of ['/capital-markets/portfolio', '/capital-markets/performance',
    '/capital-markets/risk', '/capital-markets/data-quality',
    '/capital-markets/opportunities/unprivileged-canary']) {
    await requireStatus(await page.goto(absolute(route), { waitUntil: 'domcontentloaded' }), 403, route);
  }

  await requireStatus(await page.goto(absolute('/capital-markets/opportunities'), { waitUntil: 'domcontentloaded' }), 200, 'signal board');
  const signalBoard = await page.locator('[data-cm-decision-workspace="opportunities"]').innerText();
  if (!signalBoard.includes('Opportunity Board')) {
    throw new Error('OpportunityView lost its permitted Opportunity Board.');
  }
  const exportResponse = await context.request.get(absolute('/capital-markets/export/opportunities.json'));
  await requireStatus(exportResponse, 200, 'opportunity export');
  const exported = await exportResponse.json();
  if (exported.dataset !== 'opportunities' || !Array.isArray(exported.data)) {
    throw new Error('Restricted opportunity export schema is invalid.');
  }
  for (const row of exported.data) {
    if (row.approved_capital !== null || row.portfolio_impact !== 'RESTRICTED'
      || row.decision !== 'RESTRICTED' || row.priority !== null || row.reason !== '') {
      throw new Error('Restricted opportunity export leaked portfolio allocation.');
    }
  }

  await requireStatus(await page.goto(absolute('/capital-markets/agents'), { waitUntil: 'domcontentloaded' }), 200, 'agent center');
  const agentText = await page.locator('[data-cm-decision-workspace="agents"]').innerText();
  if (!agentText.includes('Detailed agent evidence is restricted')) {
    throw new Error('General View did not disclose limited agent evidence access.');
  }
  if (agentText.includes('Structured Result') || agentText.includes('audited tool call')) {
    throw new Error('General View leaked agent audit details.');
  }

  console.log('Capital Markets restricted-principal browser acceptance passed.');
} finally {
  await browser.close();
}
