import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

// Isolated COS UX-00 acceptance. This test is deliberately NOT runnable against
// an arbitrary URL or live tenant, because it performs real Property writes.
const base = process.env.UX00_E2E_BASE_URL || '';
const email = process.env.UX00_E2E_EMAIL || '';
const password = process.env.UX00_E2E_PASSWORD || '';
const foreignEmail = process.env.UX00_E2E_FOREIGN_EMAIL || '';
const foreignPassword = process.env.UX00_E2E_FOREIGN_PASSWORD || '';
const chrome = process.env.CHROME_PATH || '';
const evidenceDir = 'tmp/ux00-r1-r2-r3';
const uid = String(process.env.GITHUB_RUN_ID || Date.now()).replace(/[^a-zA-Z0-9]/g, '').slice(-16);
const checks = [];
const add = (name, evidence = {}) => checks.push({ name, status: 'PASS', ...evidence });
const assert = (truth, why) => { if (!truth) throw new Error(why); };
const abs = (path) => new URL(path, base).toString();
const json = async (res, label, code = 200) => {
  const text = await res.text();
  assert(res.status() === code, label + ': HTTP ' + res.status() + ': ' + text.slice(0, 800));
  try { return JSON.parse(text); } catch { throw new Error(label + ': invalid JSON'); }
};
const login = async (context, user, pass) => {
  const page = await context.newPage();
  const response = await page.goto(abs('/auth/login'), { waitUntil: 'domcontentloaded' });
  assert(response?.status() === 200, 'Login form unavailable');
  await page.locator('input[name="email"]').fill(user);
  await page.locator('input[name="password"]').fill(pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 30000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  return page;
};
const authToken = async page => {
  const value = await page.locator('input[name="csrf_token"]').first().inputValue();
  assert(value.length > 10, 'Missing authenticated session CSRF token');
  return value;
};
const invoke = async (ctx, id, input, csrf, key = '') => json(
  await ctx.request.post(abs('/api/v1/capabilities/' + id + '/invoke'), {
    data: input,
    headers: { 'X-CSRF-Token': csrf, ...(key ? { 'X-Idempotency-Key': key } : {}) },
    maxRedirects: 0,
  }),
  'Capability ' + id,
);
const sqlCount = (assetId) => {
  assert(/^PROP-[A-F0-9]{20}$/.test(assetId), 'Unsafe asset reference');
  const user = process.env.MYSQL_USER || 'cos';
  const pass = process.env.MYSQL_PASSWORD || '';
  const db = process.env.MYSQL_DATABASE || 'cos';
  const query = "SELECT COUNT(*) FROM tn_property_assets WHERE organization_id='default' AND asset_id='" + assetId + "'";
  const stdout = execFileSync('docker', [
    'compose', 'exec', '-T', 'mysql', 'mysql', '-N', '-s',
    '-u' + user, '-p' + pass, db, '-e', query,
  ], { encoding: 'utf8', timeout: 15000 });
  const value = stdout.trim();
  assert(/^\d+$/.test(value), 'Cannot verify exact native Property row count: ' + value);
  return Number(value);
};

if (process.env.UX00_E2E_MUTATION_SAFE !== '1'
  || !/^http:\/\/(127\.0\.0\.1|localhost):8081\/?$/.test(base)
  || !email || !password || !foreignEmail || !foreignPassword) {
  throw new Error('UX-00 acceptance requires explicit mutation-safe localhost CI fixtures and two identities.');
}

mkdirSync(evidenceDir, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(chrome ? { executablePath: chrome } : {}) });
let admin, foreign, page, foreignPage;
let assetId = '', batchId = '', planId = '';
try {
  admin = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  foreign = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  page = await login(admin, email, password);
  foreignPage = await login(foreign, foreignEmail, foreignPassword);

  // R1. The authenticated capability QueryBus exists and is tenant-scoped.
  assert((await page.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' }))?.status() === 200,
    'R1/R2: Capability workspace missing');
  const csrf = await authToken(page);
  assert((await foreignPage.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' }))?.status() === 200,
    'R1: Foreign manager capability workspace missing');
  const foreignCsrf = await authToken(foreignPage);
  const initialSearch = await invoke(admin, 'property.asset.search', { q: 'ux00-nothing-' + uid }, csrf);
  assert(initialSearch.ok === true && Array.isArray(initialSearch.data?.items)
    && initialSearch.data?.pagination?.total === 0, 'R1: empty typed Property Query is broken');
  const initialForeign = await invoke(foreign, 'property.asset.search', { q: 'ux00-nothing-' + uid }, foreignCsrf);
  assert(initialForeign.ok === true && initialForeign.data?.pagination?.total === 0,
    'R1: Foreign tenant empty Query incorrect');
  add('R1.empty_and_tenant_scoped_query', { mode: 'real QueryBus, desktop+mobile authenticated contexts' });

  // R2. The only UI writer is the manager form calling the canonical Domain Gateway.
  const form = page.locator('form[action="/workspace/capabilities/property/create"]');
  assert(await form.count() === 1, 'R2: Property UI create form is missing');
  const key = await form.locator('input[name="idempotency_key"]').inputValue();
  const badCsrf = await admin.request.post(abs('/workspace/capabilities/property/create'), {
    form: { csrf_token: 'bad-token', idempotency_key: 'ux00-invalid-' + uid,
      type_code: 'house', city: 'Львів', address: 'UX00 invalid ' + uid },
    maxRedirects: 0,
  });
  assert(badCsrf.status() === 400, 'R2: invalid CSRF did not block Property create');
  add('R2.csrf_fail_closed');

  const city = 'Львів';
  const address = 'UX00 browser ' + uid;
  const input = { csrf_token: csrf, idempotency_key: key,
    type_code: 'house', city, address, region: 'Львівська', area_total: '85.5' };
  await form.locator('input[name="type_code"]').fill(input.type_code);
  await form.locator('input[name="city"]').fill(city);
  await form.locator('input[name="address"]').fill(address);
  await form.locator('input[name="region"]').fill(input.region);
  await form.locator('input[name="area_total"]').fill(input.area_total);
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/workspace/capabilities'
      && url.searchParams.has('reference'), { timeout: 30000 }),
    form.locator('button[type="submit"]').click(),
  ]);
  assetId = new URL(page.url()).searchParams.get('reference') || '';
  assert(/^PROP-[A-F0-9]{20}$/.test(assetId), 'R2: invalid created Property Asset ID');
  await page.locator('.cos-alert--success').first().waitFor({ state: 'visible', timeout: 15000 });
  const read = await invoke(admin, 'property.asset.get', { reference: assetId }, csrf);
  assert(read.ok === true && read.data?.property?.asset_id === assetId
    && read.data.property.type_code === 'house', 'R2: independent native readback failed');
  assert(sqlCount(assetId) === 1, 'R2: initial create produced !=1 native Property row');
  add('R2.browser_create_native_readback', { asset_id: assetId });

  // Same idempotency key MUST NOT create a second row and must keep visible
  // independent-read confirmation, including on the replay response shape.
  const replay = await admin.request.post(abs('/workspace/capabilities/property/create'), {
    form: input, maxRedirects: 0,
  });
  assert(replay.status() === 303, 'R2: replay unexpected HTTP ' + replay.status());
  const replayUrl = new URL(replay.headers()['location'] || '', base);
  assert(replayUrl.searchParams.get('reference') === assetId,
    'R2: same-key replay lost verified original Property reference');
  assert(sqlCount(assetId) === 1, 'R2: replay wrote duplicate native Property row');
  add('R2.replay_exactly_once', { asset_id: assetId, native_rows: 1 });

  // R1 positive read and search. Property Assets only enter the canonical
  // search when an Inventory item is available; do not fake a search result.
  const inventoryCreate = await json(
    await admin.request.post(abs('/api/v1/properties/' + assetId + '/inventory'), {
      data: { transaction_type: 'sale', status: 'available',
        price_amount: 100000, price_currency: 'USD' },
      headers: { 'X-CSRF-Token': csrf, 'X-Idempotency-Key': 'ux00-inventory-' + uid },
      maxRedirects: 0,
    }), 'R1 available inventory fixture', 201,
  );
  assert(inventoryCreate.ok === true && inventoryCreate.data?.asset_id === assetId,
    'R1: cannot register searchable available Inventory');
  const found = await invoke(admin, 'property.asset.search', { q: assetId, page: 1, per_page: 10 }, csrf);
  assert(found.ok === true && found.data.pagination.total === 1
    && found.data.items.length === 1
    && found.data.items[0].property.asset_id === assetId,
  'R1: typed tenant search cannot find newly created available Property');
  const direct = await json(await admin.request.get(abs('/api/v1/properties/' + assetId)),
    'R1 direct Property API read');
  assert(direct.ok && direct.data?.property?.asset_id === assetId,
    'R1: native Property API read mismatch');
  const foreignGet = await invoke(foreign, 'property.asset.get', { reference: assetId }, foreignCsrf);
  assert(foreignGet.ok && foreignGet.data === null, 'R1: cross-tenant canonical Get leaked Property');
  const foreignSearch = await invoke(foreign, 'property.asset.search', { q: assetId }, foreignCsrf);
  assert(foreignSearch.ok && foreignSearch.data?.pagination?.total === 0,
    'R1: cross-tenant canonical Search leaked Property');
  const foreignDirect = await foreign.request.get(abs('/api/v1/properties/' + assetId));
  assert(foreignDirect.status() === 404, 'R1: foreign tenant direct GET must be 404');
  await foreignPage.goto(abs('/workspace/capabilities?reference=' + assetId));
  assert(await foreignPage.locator('.cos-alert--success').count() === 0,
    'R1: foreign UI displayed verified Property belonging to another tenant');
  add('R1.positive_search_read_and_cross_tenant_denial', { asset_id: assetId });

  // R3 UI. Upload rejects duplicate identities, then stages a bounded
  // real CSV, projects an immutable digest, proposes a Goal/Plan and remains
  // fail-closed without two independent approvals and worker admission.
  assert((await page.goto(abs('/workspace/property/bulk'), { waitUntil: 'networkidle' }))?.status() === 200,
    'R3: bulk review workspace missing');
  const csvHeader = 'type_code,city,address,area_total\n';
  const lineA = 'house,Львів,UX00 bulk ' + uid + ' A,100\n';
  const lineB = 'apartment,Львів,UX00 bulk ' + uid + ' B,60\n';
  const duplicate = await admin.request.post(abs('/workspace/property/bulk/upload'), {
    multipart: {
      csrf_token: csrf,
      file: { name: 'ux00-duplicate.csv', mimeType: 'text/csv',
        buffer: Buffer.from(csvHeader + lineA + lineA, 'utf8') },
    },
    maxRedirects: 0,
  });
  assert(duplicate.status() === 422, 'R3: duplicate CSV accepted');
  add('R3.duplicate_csv_rejected');

  const uploader = page.locator('form[action="/workspace/property/bulk/upload"]');
  await uploader.locator('input[type="file"]').setInputFiles({
    name: 'ux00-property.csv', mimeType: 'text/csv',
    buffer: Buffer.from(csvHeader + lineA + lineB, 'utf8'),
  });
  await Promise.all([
    page.waitForURL(url => url.pathname === '/workspace/property/bulk'
      && url.searchParams.has('batch'), { timeout: 30000 }),
    uploader.locator('button[type="submit"]').click(),
  ]);
  batchId = new URL(page.url()).searchParams.get('batch') || '';
  assert(/^bulk-[a-f0-9]{24}$/.test(batchId), 'R3: invalid staged batch ID');
  const staged = await json(await admin.request.get(abs('/api/v1/property/bulk/' + batchId)),
    'R3 staged native read');
  assert(staged.state === 'staged' && staged.count === 2
    && /^[a-f0-9]{64}$/.test(staged.digest)
    && staged.items.every(item => item.native_asset_id === null),
    'R3: staged batch must contain exactly 2 unexecuted items');
  const foreignBatch = await foreign.request.get(abs('/api/v1/property/bulk/' + batchId));
  assert([403, 404].includes(foreignBatch.status()), 'R3: foreign tenant can read Property batch');
  add('R3.browser_csv_stage_preview_tenant_denial', { batch_id: batchId, items: 2 });

  const badDigest = await admin.request.post(
    abs('/workspace/property/bulk/' + batchId + '/propose'),
    { form: { csrf_token: csrf, digest: '0'.repeat(64) }, maxRedirects: 0 },
  );
  assert(badDigest.status() === 422, 'R3: proposal with forged digest succeeded');
  const propose = page.locator('form[action="/workspace/property/bulk/' + batchId + '/propose"]');
  assert(await propose.count() === 1, 'R3: operator proposal form missing');
  await Promise.all([
    page.waitForURL(url => url.pathname === '/workspace/property/bulk'
      && url.searchParams.get('batch') === batchId
      && url.searchParams.get('notice') === 'planned', { timeout: 30000 }),
    propose.locator('button[type="submit"]').click(),
  ]);
  const planned = await json(await admin.request.get(abs('/api/v1/property/bulk/' + batchId)),
    'R3 planned native read');
  planId = planned.plan_id || '';
  assert(planned.state === 'planned' && /^goal-[a-z0-9]{24}$/.test(planned.goal_id || '')
    && /^plan-[a-z0-9]{24}$/.test(planId)
    && planned.items.every(item => item.native_asset_id === null),
    'R3: Plan proposal must not execute Property writes');
  const earlyStart = await admin.request.post(abs('/workspace/goals/plans/' + planId + '/start'), {
    form: { csrf_token: csrf }, maxRedirects: 0,
  });
  assert(earlyStart.status() === 303
    && (earlyStart.headers()['location'] || '').includes('blocker='),
    'R3: unapproved Goal/Plan start must remain blocked');
  const untouched = await json(await admin.request.get(abs('/api/v1/property/bulk/' + batchId)),
    'R3 blocked execution readback');
  assert(untouched.state === 'planned'
    && untouched.items.every(item => item.native_asset_id === null),
    'R3: a Plan started work without independent approvals');
  add('R3.browser_plan_proposal_and_fail_closed_run', {
    batch_id: batchId, plan_id: planId, native_writes_before_approval: 0,
  });

  const report = { schema: 1, suite: 'COS UX-00 R1/R2/R3 browser acceptance',
    environment: 'GitHub Actions disposable localhost MySQL', run_id: uid,
    checks, tested_at: new Date().toISOString(),
    remaining_gate: 'R3 real Action approval and worker E2E covered by separate Federation MySQL CI job; staging rollout/operator sign-off not covered' };
  writeFileSync(evidenceDir + '/results.json', JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify({ ok: true, ...report }, null, 2));
} catch (error) {
  if (page) {
    await page.screenshot({ path: evidenceDir + '/failure.png', fullPage: true }).catch(() => {});
  }
  writeFileSync(evidenceDir + '/failure.json', JSON.stringify({
    ok: false, run_id: uid, asset_id: assetId, batch_id: batchId,
    checks, error: String(error?.stack || error),
  }, null, 2) + '\n');
  throw error;
} finally {
  await browser.close();
}
