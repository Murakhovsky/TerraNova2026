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

async function goto200(path, marker, expectedText = '') {
  const response = await page.goto(new URL(path, baseUrl).toString(), {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  const status = response?.status() ?? 0;
  if (status !== 200) {
    const body = (await page.locator('body').innerText().catch(() => '')).slice(0, 1200).replace(/\s+/g, ' ');
    throw new Error(path + ' expected HTTP 200, received ' + status + '; body=' + body);
  }
  if (marker && await page.locator(marker).count() === 0) {
    throw new Error(path + ' is missing expected marker ' + marker);
  }
  if (expectedText) {
    const body = await page.locator('body').innerText();
    if (!body.includes(expectedText)) {
      throw new Error(path + ' is missing expected text: ' + expectedText);
    }
  }
}

async function saveContent({ type, title, slug, excerpt }) {
  await goto200('/admin/content/edit', 'form[action="/admin/content/save/0"]');
  const form = page.locator('form[action="/admin/content/save/0"]');
  await form.locator('input[name="title"]').fill(title);
  await form.locator('select[name="content_type"]').selectOption(type);
  await form.locator('select[name="status"]').selectOption('published');
  await form.locator('input[name="slug"]').fill(slug);
  await form.locator('textarea[name="excerpt"]').fill(excerpt);
  await form.locator('textarea[name="body_html"]').fill('<p>Dynamic UI detail acceptance content.</p>');
  await form.locator('input[name="meta_title"]').fill(title + ' | COS');
  await form.locator('textarea[name="meta_description"]').fill('Dynamic UI detail acceptance verifies canonical content routing and rendering.');
  await form.locator('select[name="robots"]').selectOption('index,follow');

  await Promise.all([
    page.waitForURL((url) => /\/admin\/content\/edit\/[1-9][0-9]*/.test(url.pathname), { timeout: 30000 }),
    form.locator('button[type="submit"]').click(),
  ]);

  const match = new URL(page.url()).pathname.match(/\/admin\/content\/edit\/([1-9][0-9]*)/);
  if (!match) throw new Error('Content save did not navigate to a dynamic editor route.');
  await goto200('/admin/content/edit/' + match[1], 'form[action="/admin/content/save/' + match[1] + '"]', title);
  return match[1];
}

try {
  await goto200('/auth/login', 'form[action="/auth/login"]');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    page.locator('button[type="submit"]').click(),
  ]);

  const articleId = await saveContent({
    type: 'blog_post',
    title: 'Dynamic Article Acceptance',
    slug: 'dynamic-article-acceptance',
    excerpt: 'Dynamic article acceptance.',
  });
  await goto200('/blog/dynamic-article-acceptance', '[data-cos-public="article"]', 'Dynamic Article Acceptance');

  const guideId = await saveContent({
    type: 'seo_landing',
    title: 'Dynamic Guide Acceptance',
    slug: 'dynamic-guide-acceptance',
    excerpt: 'Dynamic guide acceptance.',
  });
  await goto200('/guide/dynamic-guide-acceptance', '[data-cos-public="guide"]', 'Dynamic Guide Acceptance');

  await goto200('/growth/accounts/UI-ACCOUNT-DETAIL-001', '.cos-growth-workspace', 'Dynamic Account Acceptance');
  await goto200('/growth/candidates/UI-CAND-DETAIL-001', '[data-growth-candidate][data-candidate-id="UI-CAND-DETAIL-001"]', 'UI-ACCOUNT-DETAIL-001');
  await goto200('/growth/experiments/UI-GEXP-DETAIL-001', '[data-growth-experiment][data-experiment-id="UI-GEXP-DETAIL-001"]', 'Dynamic Experiment Acceptance');

  await goto200('/sales/admin/rules/ui-dynamic-rule-001', '[data-controller="sales-admin-rule-editor"]', 'Dynamic Rule Acceptance');

  await goto200('/sales/admin/agents', '[data-cos-archetype]');
  const agentHref = await page.locator('a[href^="/sales/admin/agents/"]').first().getAttribute('href');
  if (!agentHref) throw new Error('Sales agent list did not provision a dynamic detail target.');
  await goto200(agentHref, '[data-controller="sales-admin-agent"]', 'sales_intelligence');

  await goto200('/diagnostics/ui-diagnostic-detail-001/report', '[data-cos-system="diagnostic-report"]', 'Dynamic Diagnostic Acceptance');
  await goto200('/spatial/scene/ui-dynamic-detail-scene', '[data-cos-public="spatial-scene"]', 'Dynamic Spatial Acceptance');

  console.log(JSON.stringify({
    ok: true,
    suite: 'Production Cutover dynamic detail acceptance',
    dynamic: {
      articleId,
      guideId,
      growthAccount: 'UI-ACCOUNT-DETAIL-001',
      growthCandidate: 'UI-CAND-DETAIL-001',
      growthExperiment: 'UI-GEXP-DETAIL-001',
      salesRule: 'ui-dynamic-rule-001',
      salesAgent: agentHref,
      diagnostic: 'ui-diagnostic-detail-001',
      spatial: 'ui-dynamic-detail-scene',
    },
  }, null, 2));
} finally {
  await context.close();
  await browser.close();
}
