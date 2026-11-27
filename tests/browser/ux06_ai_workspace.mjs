import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';

// Read-only UX-06 acceptance against explicitly provisioned, disposable localhost
// fixtures. The test does not submit a Plan, approve Actions or run Hermes.
const env = process.env;
const base = env.UX06_E2E_BASE_URL || '';
const email = env.UX06_E2E_EMAIL || '';
const password = env.UX06_E2E_PASSWORD || '';
const restrictedEmail = env.UX06_E2E_RESTRICTED_EMAIL || '';
const restrictedPassword = env.UX06_E2E_RESTRICTED_PASSWORD || '';
const campaign = env.UX06_E2E_CAMPAIGN_ID || '';
const foreignCampaign = env.UX06_E2E_FOREIGN_CAMPAIGN_ID || '';
const id = /^mkt-[a-f0-9]{24}$/;
if (env.UX06_E2E_FIXTURE_SAFE !== '1'
    || !/^http:\/\/(127\.0\.0\.1|localhost):8081\/?$/.test(base)
    || !email || !password || !restrictedEmail || !restrictedPassword
    || !id.test(campaign) || !id.test(foreignCampaign) || campaign === foreignCampaign) {
  throw new Error('UX-06 requires disposable localhost manager/member and two distinct tenant Campaign fixtures.');
}

const output = 'tmp/ux06-ai-workspace';
mkdirSync(output, { recursive: true });
const checks = [];
const assert = (condition, reason) => { if (!condition) throw new Error(reason); };
const pass = (name, details = {}) => checks.push({ name, status: 'PASS', ...details });
const url = (path) => new URL(path, base).toString();
const workspace = (entity, standalone = true) => {
  const params = new URLSearchParams({ workspace: 'marketing.campaigns', entity: 'marketing_campaign:' + entity });
  if (standalone) params.set('view', 'workspace');
  return url('/workspace/ai?' + params.toString());
};
const browser = await chromium.launch({
  headless: true, ...(env.CHROME_PATH ? { executablePath: env.CHROME_PATH } : {}),
});
const login = async (context, user, secret) => {
  const page = await context.newPage();
  const response = await page.goto(url('/auth/login'), { waitUntil: 'domcontentloaded' });
  assert(response?.status() === 200, 'Login unavailable');
  await page.locator('input[name="email"]').fill(user);
  await page.locator('input[name="password"]').fill(secret);
  const submit = async () => {
    const submitted = page.waitForResponse(response => new URL(response.url()).pathname === '/auth/login'
      && response.request().method() === 'POST');
    await page.locator('button[type="submit"]').click();
    return submitted;
  };
  let result = await submit();
  if (result.status() === 429 && env.GITHUB_ACTIONS === 'true') {
    const seconds = Number(result.headers()['retry-after']);
    assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 300, 'Invalid auth Retry-After');
    await new Promise(resolve => setTimeout(resolve, (seconds + 1) * 1000));
    await page.goto(url('/auth/login'), { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(user);
    await page.locator('input[name="password"]').fill(secret);
    result = await submit();
  }
  assert([302, 303].includes(result.status()), 'Disposable fixture login rejected');
  await page.waitForURL(location => !location.pathname.startsWith('/auth/login'));
  return page;
};
const guard = async (context) => {
  // Prevent any unsafe HTTP write even if an unrelated browser component changes.
  await context.route('**/*', async route => {
    if (!['GET', 'HEAD', 'OPTIONS'].includes(route.request().method())) {
      await route.abort();
      return;
    }
    await route.continue();
  });
};

try {
  const manager = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await login(manager, email, password);
  await guard(manager);
  const response = await page.goto(workspace(campaign), { waitUntil: 'domcontentloaded' });
  assert(response?.status() === 200, 'Owned campaign workspace failed');
  await page.locator('#cos-ai-runtime').waitFor();
  assert(response.headers()['cache-control']?.includes('no-store'), 'AI workspace may be cached');
  assert(await page.locator('[data-cos-ai-center]').count() === 1, 'Missing shared AI body');
  assert(await page.locator('#cos-hermes-journal').count() === 1, 'Native journal missing');
  assert(await page.locator('a[href="/workspace/goals"]').count() > 0, 'Canonical Goals unavailable');
  const forms = page.locator('form[action$="/analyst-plans"]');
  if (env.UX06_E2E_EXPECT_BLOCKED === '1') {
    assert(await forms.count() === 0, 'Unconfigured disposable tenant can propose Hermes work');
  }
  if (await forms.count()) {
    assert(await forms.locator('input[name="csrf_token"]').count() === 1, 'Missing CSRF');
    assert(await forms.locator('input[name="campaign_revision"]').count() === 1, 'Missing revision pin');
    assert(await forms.locator('input[name="idempotency_key"]').count() === 1, 'Missing idempotency');
    assert(/^[a-f0-9]{64}$/.test(await forms.locator('input[name="workspace_configuration_hash"]').inputValue()), 'Missing approved configuration pin');
  }
  pass('Owned context and governed Plan-or-blocked state', { delegationFormAvailable: await forms.count() === 1 });

  for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    await page.setViewportSize(viewport);
    const center = page.locator('[data-cos-ai-center]');
    assert(await center.evaluate(node => node.scrollWidth <= node.clientWidth + 2), 'AI content overflows viewport');
    const report = await new AxeBuilder({ page }).include('[data-cos-ai-center]')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const severe = report.violations.filter(violation => ['serious', 'critical'].includes(violation.impact));
    writeFileSync(output + '/axe-' + viewport.width + '.json', JSON.stringify(report.violations, null, 2));
    await page.screenshot({ path: output + '/workspace-' + viewport.width + '.png', fullPage: true });
    assert(severe.length === 0, 'Serious AI Workspace accessibility violations: ' + severe.map(item => item.id).join(', '));
    pass('Responsive layout and accessibility ' + viewport.width);
  }

  const fragment = await manager.request.get(workspace(campaign, false));
  assert(fragment.status() === 200 && (await fragment.text()).includes('id="cos-ai-panel-frame"'),
    'Inline panel and standalone workspace diverged');
  pass('Inline frame and standalone body');

  const foreign = await page.goto(workspace(foreignCampaign), { waitUntil: 'domcontentloaded' });
  assert(foreign?.status() === 200 && await page.locator('.cos-agent-warning[role="alert"]').count() > 0,
    'Foreign scope not explicitly withheld');
  assert(await page.locator('#cos-hermes-journal, form[action$="/analyst-plans"], .cos-agent-run').count() === 0,
    'Foreign campaign leaked evidence or delegation');
  assert(!(await page.locator('[data-cos-ai-center]').textContent()).includes('UX06 PRIVATE FOREIGN campaign'),
    'Foreign campaign name disclosed');
  const invalid = await page.goto(url('/workspace/ai?view=workspace&workspace=unknown&entity=marketing_campaign%3A' + campaign));
  assert(invalid?.status() === 200 && await page.locator('.cos-agent-warning[role="alert"]').count() > 0,
    'Invalid scope not withheld');
  assert(await page.locator('.cos-agent-run, form[action$="/analyst-plans"]').count() === 0,
    'Invalid scope broadened to tenant history');
  pass('Foreign and invalid scope fail closed');

  const restricted = await browser.newContext();
  const restrictedPage = await login(restricted, restrictedEmail, restrictedPassword);
  await guard(restricted);
  const denied = await restrictedPage.goto(workspace(campaign), { waitUntil: 'domcontentloaded' });
  assert(denied?.status() === 403, 'Restricted fixture admitted to AI history');
  pass('Restricted actor denied');
  await restricted.close();
  await manager.close();
} finally {
  writeFileSync(output + '/checks.json', JSON.stringify(checks, null, 2));
  await browser.close();
}

console.log('UX06 read-only browser acceptance PASS');
