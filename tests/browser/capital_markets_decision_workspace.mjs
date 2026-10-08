import { chromium } from 'playwright-core';

const baseUrl = process.env.COS_WEB_SMOKE_BASE_URL || '';
const email = process.env.COS_WEB_SMOKE_EMAIL || '';
const password = process.env.COS_WEB_SMOKE_PASSWORD || '';
const executablePath = process.env.CHROME_PATH || '';

if (!baseUrl || !email || !password) {
  throw new Error('Capital Markets Decision Workspace browser acceptance requires base URL and smoke credentials.');
}

const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
const absolute = (path) => new URL(path, baseUrl).toString();
const assertOk = async (response, label) => {
  if (!response || response.status() >= 400) {
    let body = '';
    try {
      body = response ? (await response.text()).slice(0, 4000) : '';
    } catch {}
    throw new Error(label + ' returned ' + (response?.status() ?? 'no response') + (body ? '\n' + body : ''));
  }
};

try {
  const loginContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const login = await loginContext.newPage();
  await assertOk(await login.goto(absolute('/auth/login'), { waitUntil: 'domcontentloaded' }), 'login');
  await login.locator('input[name="email"]').fill(email);
  await login.locator('input[name="password"]').fill(password);
  await Promise.all([
    login.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    login.locator('button[type="submit"]').click(),
  ]);
  const storageState = await loginContext.storageState();
  await loginContext.close();

  const routeExpectations = [
    ['/capital-markets', ['Portfolio Equity', 'Recommended Actions', 'Capital Map']],
    ['/capital-markets/opportunities', ['Opportunity Board', 'Min expected net', 'Export CSV']],
    ['/capital-markets/markets', ['Market Explorer', 'Instrument View', 'Relationship View', 'Venue View']],
    ['/capital-markets/research', ['Research Pipeline', 'Rejected Research']],
    ['/capital-markets/strategies', ['Strategy Lab']],
    ['/capital-markets/portfolio', ['Capital Map', 'Economic Exposure', 'Capital Location']],
    ['/capital-markets/allocation', ['Recommended Allocation', 'Scenario Simulator', 'Simulate portfolio impact']],
    ['/capital-markets/execution', ['Execution Groups']],
    ['/capital-markets/risk', ['Limits / Headroom', 'Top Risks', 'Stress Tests']],
    ['/capital-markets/performance', ['Profit Factor', 'P&L Attribution', 'Edge Funnel', 'Gross → Costs → Net', 'Cost Breakdown']],
    ['/capital-markets/agents', ['Agent Authority', 'Recent Agent Runs']],
    ['/capital-markets/data-quality', ['Sources Online', 'Market Quality', 'Quote Age', 'Book Age', 'Reference Age', 'Why Untrusted?', 'Application health']],
  ];

  for (const profile of [
    { name: 'desktop', viewport: { width: 1440, height: 1000 }, mobile: false, paths: routeExpectations },
    {
      name: 'mobile',
      viewport: { width: 390, height: 844 },
      mobile: true,
      paths: routeExpectations.filter(([path]) => ['/capital-markets', '/capital-markets/opportunities', '/capital-markets/portfolio', '/capital-markets/risk', '/capital-markets/execution'].includes(path)),
    },
  ]) {
    const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.mobile, storageState });
    const page = await context.newPage();

    for (const [path, markers] of profile.paths) {
      const response = await page.goto(absolute(path), { waitUntil: 'domcontentloaded' });
      await assertOk(response, profile.name + ': ' + path);
      await page.locator('[data-cm-decision-workspace]').waitFor({ state: 'visible' });

      const text = await page.locator('[data-cm-decision-workspace]').innerText();
      for (const marker of markers) {
        if (!text.includes(marker)) {
          throw new Error(profile.name + ': ' + path + ' missing marker "' + marker + '"');
        }
      }
      if (!text.includes('LIVE · DISABLED')) {
        throw new Error(profile.name + ': ' + path + ' must expose explicit LIVE disabled state.');
      }
      if (text.includes('Execute Live')) {
        throw new Error(profile.name + ': ' + path + ' must not expose Live execution.');
      }

      const primaryLinks = page.locator('nav[aria-label="Capital Markets primary workspace navigation"] a');
      if (await primaryLinks.count() < 12) {
        throw new Error(profile.name + ': ' + path + ' primary Decision Workspace navigation is incomplete.');
      }

      if (profile.mobile) {
        const width = await page.evaluate(() => document.documentElement.scrollWidth);
        const viewportWidth = profile.viewport.width;
        if (width > viewportWidth * 1.8) {
          throw new Error(profile.name + ': ' + path + ' has uncontrolled page-level horizontal overflow (' + width + 'px).');
        }
      }
    }

    if (!profile.mobile) {
      await page.goto(absolute('/capital-markets/opportunities'), { waitUntil: 'domcontentloaded' });
      const orderBefore = await page.locator('table[data-cm-table="opportunities"] thead [data-cm-col]').evaluateAll(
        (cells) => cells.map((cell) => cell.dataset.cmCol),
      );
      const columnsPanel = page.locator('details').filter({ has: page.locator('summary', { hasText: 'Columns / order' }) });
      await columnsPanel.locator('summary').click();
      const statusMoveLeft = page.locator('button[data-cm-table-id="opportunities"][data-cm-column="status"][data-cm-direction="-1"]');
      await statusMoveLeft.click();
      const orderAfter = await page.locator('table[data-cm-table="opportunities"] thead [data-cm-col]').evaluateAll(
        (cells) => cells.map((cell) => cell.dataset.cmCol),
      );
      if (orderBefore.join('|') === orderAfter.join('|')) {
        throw new Error('desktop: Opportunity column order control did not change the table.');
      }
      await page.reload({ waitUntil: 'domcontentloaded' });
      const orderReloaded = await page.locator('table[data-cm-table="opportunities"] thead [data-cm-col]').evaluateAll(
        (cells) => cells.map((cell) => cell.dataset.cmCol),
      );
      if (orderReloaded.join('|') !== orderAfter.join('|')) {
        throw new Error('desktop: Opportunity column order did not persist across reload.');
      }

      const opportunitiesJson = await context.request.get(absolute('/capital-markets/export/opportunities.json'));
      if (!opportunitiesJson.ok()) {
        throw new Error('desktop: opportunities JSON export returned ' + opportunitiesJson.status());
      }
      const opportunitiesPayload = await opportunitiesJson.json();
      if (opportunitiesPayload.dataset !== 'opportunities' || !Array.isArray(opportunitiesPayload.data)) {
        throw new Error('desktop: opportunities JSON export has invalid canonical payload.');
      }

      const performanceCsv = await context.request.get(absolute('/capital-markets/export/performance.csv'));
      if (!performanceCsv.ok()) {
        throw new Error('desktop: performance CSV export returned ' + performanceCsv.status());
      }
      const contentType = performanceCsv.headers()['content-type'] || '';
      if (!contentType.includes('text/csv')) {
        throw new Error('desktop: performance CSV export has unexpected content type: ' + contentType);
      }
    }

    await page.goto(absolute('/capital-markets'), { waitUntil: 'domcontentloaded' });
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      return el ? { tag: el.tagName, text: (el.textContent || '').trim(), aria: el.getAttribute('aria-label') || '' } : null;
    });
    if (!focused || !['A', 'BUTTON', 'SELECT', 'INPUT'].includes(focused.tag)) {
      throw new Error(profile.name + ': critical workspace is not keyboard reachable from initial tab navigation.');
    }

    await context.close();
  }

  console.log(JSON.stringify({ ok: true, suite: 'CM-DECISION-WORKSPACE browser acceptance' }, null, 2));
} finally {
  await browser.close();
}
