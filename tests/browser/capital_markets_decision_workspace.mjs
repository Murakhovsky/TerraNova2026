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
    ['/capital-markets/performance', ['Profit Factor', 'P&L Attribution', 'Edge Funnel', 'Gross → Costs → Net', 'Cost Breakdown', 'NAV source integrity', 'Unreconciled venue cash statement differences', 'Наступні дії для звірки NAV']],
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

    // A frozen Mercure subscription must never leave the decision snapshot looking current.
    // Force a historical server-render timestamp to test the presentational watchdog
    // without sleeping a minute or changing canonical backend financial state.
    await page.goto(absolute('/capital-markets'), { waitUntil: 'domcontentloaded' });
    const snapshotRoot = page.locator('[data-cm-decision-workspace="overview"]');
    if (!await snapshotRoot.getAttribute('data-cm-snapshot-rendered-at')) {
      throw new Error(profile.name + ': server-rendered snapshot timestamp is missing.');
    }
    await snapshotRoot.evaluate((element) => {
      element.dataset.cmSnapshotRenderedAt = new Date(Date.now() - 75000).toISOString();
    });
    const warning = page.locator('[data-capital-markets-workspace-target="snapshotWarning"]');
    await warning.waitFor({ state: 'visible', timeout: 5000 });
    if ((await warning.innerText()).includes('Decision snapshot stale') === false) {
      throw new Error(profile.name + ': stale open decision snapshot has no explicit warning.');
    }
    await snapshotRoot.evaluate((element) => {
      element.dataset.cmSnapshotRenderedAt = new Date().toISOString();
    });
    await warning.waitFor({ state: 'hidden', timeout: 5000 });
    await snapshotRoot.evaluate((element) => {
      element.dataset.cmSnapshotRenderedAt = '';
    });
    await warning.waitFor({ state: 'visible', timeout: 5000 });
    if ((await warning.innerText()).includes('time unavailable') === false) {
      throw new Error(profile.name + ': unknown snapshot time must fail closed.');
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
      const windowsJson = await context.request.get(absolute('/capital-markets/export/realized-windows.json'));
      if (!windowsJson.ok()) {
        throw new Error('desktop: realized-window JSON export returned ' + windowsJson.status());
      }
      const windowsPayload = await windowsJson.json();
      if (windowsPayload.dataset !== 'realized-windows' || !Array.isArray(windowsPayload.data)
        || windowsPayload.data.length !== 2) {
        throw new Error('desktop: realized-window JSON export must return today and 30d projections.');
      }
      for (const row of windowsPayload.data) {
        if (!['today', '30d'].includes(row.window) || row.scope !== 'REALIZED_EXECUTIONS_ONLY'
          || !['COMPLETE','PARTIAL','UNAVAILABLE'].includes(row.status)) {
          throw new Error('desktop: realized-window projection has invalid identity, scope or coverage state.');
        }
        if (row.status !== 'COMPLETE' && row.net_pnl !== null) {
          throw new Error('desktop: incomplete realized-window export must never publish a numeric total.');
        }
        if (row.status === 'COMPLETE' && (!row.currency || row.net_pnl === null)) {
          throw new Error('desktop: complete realized-window export needs a verified currency and net value.');
        }
      }

      const contentType = performanceCsv.headers()['content-type'] || '';
      if (!contentType.includes('text/csv')) {
        throw new Error('desktop: performance CSV export has unexpected content type: ' + contentType);
      }

      await page.goto(absolute('/capital-markets/markets'), { waitUntil: 'domcontentloaded' });
      const firstMarketLink = page.locator('a[href^="/capital-markets/markets/"]').first();
      if (await firstMarketLink.count()) {
        const href = await firstMarketLink.getAttribute('href');
        const response = await page.goto(absolute(href), { waitUntil: 'domcontentloaded' });
        await assertOk(response, 'desktop: market detail');
        const text = await page.locator('[data-cm-decision-workspace]').innerText();
        for (const marker of ['Market Detail', 'Current Market', 'Historical Market Evidence · 7D', 'Relationships']) {
          if (!text.includes(marker)) throw new Error('desktop: market detail missing marker "' + marker + '"');
        }
        const history = page.locator('[aria-label="Historical Market Evidence"]');
        if (await history.count() !== 1) {
          throw new Error('desktop: market detail must expose exactly one historical evidence region.');
        }
        const historyText = await history.innerText();
        if (historyText.includes('RESTRICTED') && await history.locator('[data-history-rows]').count() !== 0) {
          throw new Error('desktop: unauthorized historical event payload must not be rendered in the DOM.');
        }
        if (await history.locator('svg[role="img"]').count() > 0) {
          const selected = history.locator('select[aria-label="Select canonical historical market series"]');
          if (await selected.count() !== 1) {
            throw new Error('desktop: historical chart must identify its canonical series.');
          }
        }
      }

      await page.goto(absolute('/capital-markets/markets?view=relationship'), { waitUntil: 'domcontentloaded' });
      const firstRelationshipLink = page.locator('a[href^="/capital-markets/relationships/"]').first();
      if (await firstRelationshipLink.count()) {
        const href = await firstRelationshipLink.getAttribute('href');
        const response = await page.goto(absolute(href), { waitUntil: 'domcontentloaded' });
        await assertOk(response, 'desktop: relationship detail');
        const text = await page.locator('[data-cm-decision-workspace]').innerText();
        for (const marker of ['Relationship Detail', 'Relationship Graph', 'Price Comparison', 'Historical Basis · 7D']) {
          if (!text.includes(marker)) throw new Error('desktop: relationship detail missing marker "' + marker + '"');
        }
        const basisRegion = page.locator('[aria-label="Historical Relationship Basis"]');
        if (await basisRegion.count() !== 1) {
          throw new Error('desktop: relationship detail must show historical comparison authority state.');
        }
        const basisText = await basisRegion.innerText();
        if (basisText.includes('RESTRICTED') && await basisRegion.locator('[data-basis-rows]').count() !== 0) {
          throw new Error('desktop: unpermitted historical basis data must never reach the DOM.');
        }
        if (basisText.includes('NOT COMPARABLE') && await basisRegion.locator('svg[role="img"]').count() > 0) {
          throw new Error('desktop: untrusted historical basis cannot be plotted.');
        }
      }

      await page.goto(absolute('/capital-markets/strategies'), { waitUntil: 'domcontentloaded' });
      const firstStrategyLink = page.locator('a[href^="/capital-markets/strategies/"]').first();
      if (await firstStrategyLink.count()) {
        const href = await firstStrategyLink.getAttribute('href');
        const response = await page.goto(absolute(href), { waitUntil: 'domcontentloaded' });
        await assertOk(response, 'desktop: strategy detail');
        const text = await page.locator('[data-cm-decision-workspace]').innerText();
        if (!text.includes('Version Comparison')) {
          throw new Error('desktop: strategy detail missing Version Comparison');
        }
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
