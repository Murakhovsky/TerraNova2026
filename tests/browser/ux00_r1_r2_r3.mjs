import { chromium } from 'playwright-core';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

// Isolated COS UX-00 acceptance. This test is deliberately NOT runnable against
// an arbitrary URL or live tenant, because it performs real Property writes.
const base = process.env.UX00_E2E_BASE_URL || '';
const email = process.env.UX00_E2E_EMAIL || '';
const password = process.env.UX00_E2E_PASSWORD || '';
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
  const visit = async () => {
    const response = await page.goto(abs('/auth/login'), { waitUntil: 'domcontentloaded' });
    assert(response?.status() === 200, 'Login form unavailable');
    await page.locator('input[name="email"]').fill(user);
    await page.locator('input[name="password"]').fill(pass);
  };
  const submit = async () => {
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/auth/login'
      && r.request().method() === 'POST', { timeout: 30000 });
    await page.locator('button[type="submit"]').click();
    return response;
  };
  await visit();
  let attempt = await submit();
  // Never bypass the actual 10-POST/300s auth limiter. Disposable localhost
  // GitHub CI may respect the server's bounded Retry-After exactly ONCE.
  if (attempt.status() === 429
      && process.env.GITHUB_ACTIONS === 'true'
      && process.env.UX00_E2E_MUTATION_SAFE === '1'
      && /^http:\/\/(?:127\.0\.0\.1|localhost):8081\/?$/.test(base)) {
    const seconds = Number(attempt.headers()['retry-after']);
    assert(Number.isInteger(seconds) && seconds >= 1 && seconds <= 300,
      'Unbounded or missing login Retry-After');
    console.log('UX-00 CI login rate-limited; respecting Retry-After=' + seconds + ' once');
    await new Promise(resolve => setTimeout(resolve, (seconds + 1) * 1000));
    await visit();
    attempt = await submit();
  }
  assert([302, 303].includes(attempt.status()),
    'Fixture login rejected HTTP ' + attempt.status() + ' for ' + user);
  await page.waitForURL(url => !url.pathname.startsWith('/auth/login'), { timeout: 30000 });
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
const sqlExecute = (query) => execFileSync('docker', [
  'compose', 'exec', '-T', 'mysql', 'mysql', '-N', '-s',
  '-u' + (process.env.MYSQL_USER || 'cos'),
  '-p' + (process.env.MYSQL_PASSWORD || ''),
  process.env.MYSQL_DATABASE || 'cos', '-e', query,
], { encoding: 'utf8', timeout: 15000 }).trim();

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
  || !email || !password) {
  throw new Error('UX-00 acceptance requires explicit mutation-safe localhost CI fixtures and a disposable authenticated fixture.');
}

mkdirSync(evidenceDir, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(chrome ? { executablePath: chrome } : {}) });
let admin, page;
let assetId = '', batchId = '', planId = '';
try {
  admin = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  page = await login(admin, email, password);

  // R1. The authenticated capability QueryBus exists and is tenant-scoped.
  const capabilityPage = await page.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' });
  if (capabilityPage?.status() !== 200) {
    const responseText = (await page.locator('body').innerText().catch(() => '')).slice(0, 1300);
    throw new Error('R1/R2 Capability workspace HTTP ' + (capabilityPage?.status() ?? 'none')
      + ' URL ' + page.url() + ' BODY ' + responseText);
  }
  const csrf = await authToken(page);

  // The current AccountAuthenticationService is explicitly pinned to
  // COS_ORGANIZATION_ID=default, so a second tenant CANNOT log into this
  // one-host fixture. Seed a native *foreign* Asset+available Inventory
  // instead, and demand that all authenticated default-tenant reads deny it.
  // This is a real two-tenant DB boundary test, not a two-login UI claim.
  const foreignAssetId = 'PROP-' + uid.padStart(20, '0').slice(-20);
  const foreignInventoryId = 'INV-' + uid.padStart(20, '0').slice(-20);
  sqlExecute("INSERT INTO tn_property_assets (organization_id,asset_id,kind,type_code,lifecycle) "
    + "VALUES ('foreign-tenant','" + foreignAssetId + "','residential','house','unknown')");
  sqlExecute("INSERT INTO tn_property_inventory_items "
    + "(organization_id,inventory_id,asset_id,transaction_type,status,price_amount,price_currency) "
    + "VALUES ('foreign-tenant','" + foreignInventoryId + "','" + foreignAssetId
    + "','sale','available',100000,'USD')");
  const initialSearch = await invoke(admin, 'property.asset.search', { q: 'ux00-nothing-' + uid }, csrf);
  assert(initialSearch.ok === true && Array.isArray(initialSearch.data?.items)
    && initialSearch.data?.pagination?.total === 0, 'R1: empty typed Property Query is broken');
  const forge = await admin.request.post(abs('/api/v1/capabilities/property.asset.search/invoke'), {
    data: { q: foreignAssetId, organization_id: 'foreign-tenant' },
    headers: { 'X-CSRF-Token': csrf }, maxRedirects: 0,
  });
  assert(forge.status() === 422, 'R1: tenant override was accepted by Search');
  add('R1.empty_query_and_scope_override_denied', { mode: 'real tenant-scoped QueryBus' });

  // R2. The only UI writer is the manager form calling the canonical Domain Gateway.
  const form = page.locator('form[action="/workspace/capabilities/property/create"]');
  assert(await form.count() === 1, 'R2: Property UI create form is missing');
  const key = await form.locator('input[name="idempotency_key"]').inputValue();
  const badCsrf = await admin.request.post(abs('/workspace/capabilities/property/create'), {
    form: { csrf_token: 'bad-token', idempotency_key: 'ux00-invalid-' + uid,
      type_code: 'house', city: 'Львів', address: 'UX00 invalid ' + uid },
    maxRedirects: 0,
  });
  assert([400, 403].includes(badCsrf.status()),
    'R2: invalid CSRF did not fail closed: HTTP ' + badCsrf.status()
      + ' body=' + (await badCsrf.text()).slice(0, 300));
  add('R2.csrf_fail_closed', { denied_status: badCsrf.status() });

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
  assert(inventoryCreate.ok === true && inventoryCreate.data?.property_asset_id === assetId,
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
  const foreignGet = await invoke(admin, 'property.asset.get', { reference: foreignAssetId }, csrf);
  assert(foreignGet.ok && foreignGet.data === null, 'R1: typed Get leaked another tenant Property');
  const foreignSearch = await invoke(admin, 'property.asset.search', { q: foreignAssetId }, csrf);
  assert(foreignSearch.ok && foreignSearch.data?.pagination?.total === 0,
    'R1: typed Search leaked another tenant available Inventory');
  const foreignDirect = await admin.request.get(abs('/api/v1/properties/' + foreignAssetId));
  assert(foreignDirect.status() === 404, 'R1: direct API leaked foreign tenant Asset');
  await page.goto(abs('/workspace/capabilities?reference=' + foreignAssetId));
  assert(await page.locator('.cos-alert--success').count() === 0,
    'R1: browser displayed verified Property belonging to another tenant');
  add('R1.positive_search_read_and_foreign_tenant_db_denial',
    { asset_id: assetId, foreign_asset: foreignAssetId });

  // S1-M: Mobile is a real read-only Property UI, not merely a resized
  // desktop screenshot. No new Command, Action, Run, or native write.
  await page.setViewportSize({ width: 390, height: 844 });
  const mobileRead = await page.goto(abs('/workspace/capabilities?reference=' + assetId),
    { waitUntil: 'networkidle' });
  assert(mobileRead?.status() === 200, 'S1-M: native Property mobile read unavailable');
  const mobileVerified = page.locator('.cos-alert--success').first();
  assert(await mobileVerified.isVisible()
    && (await mobileVerified.innerText()).includes(assetId),
    'S1-M: mobile read lacks actual tenant-scoped Property evidence');
  const mobileForm = page.locator('form[action="/workspace/capabilities/property/create"]');
  const mobileSubmit = mobileForm.locator('button[type="submit"]');
  assert(await mobileForm.isVisible() && await mobileSubmit.isVisible(),
    'S1-M: mobile Property action entry cannot be reached');
  const mobileGeometry = await mobileSubmit.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    return { left: rect.left, right: rect.right, width: window.innerWidth };
  });
  assert(mobileGeometry.left >= -1 && mobileGeometry.right <= mobileGeometry.width + 1,
    'S1-M: primary action is horizontally clipped on mobile');
  assert(sqlCount(assetId) === 1, 'S1-M: mobile reading created a native Property duplicate');
  await page.screenshot({ path: evidenceDir + '/property-mobile-read.png',
    animations: 'disabled' });
  add('S1.mobile_verified_property_read_and_action_access', {
    asset_id: assetId, viewport: '390x844', native_rows: 1,
  });
  await page.setViewportSize({ width: 1440, height: 1000 });

  // S2-N: An authenticated non-manager with a legitimate session and valid
  // CSRF must not reach the manager-only UI/API or write native Property.
  const restrictedEmail = process.env.UX00_E2E_RESTRICTED_EMAIL || '';
  const restrictedPassword = process.env.UX00_E2E_RESTRICTED_PASSWORD || '';
  assert(restrictedEmail && restrictedPassword, 'S2-N: missing restricted fixture');
  const restrictedCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  try {
    const restrictedPage = await login(restrictedCtx, restrictedEmail, restrictedPassword);
    const access = await restrictedPage.goto(abs('/account/access'), { waitUntil: 'networkidle' });
    assert(access?.status() === 200, 'S2-N: buyer account lacks legitimate account access');
    const buyerToken = await restrictedPage.locator('input[name="csrf_token"]').first().inputValue();
    assert(buyerToken.length > 10, 'S2-N: no valid buyer-session CSRF token');
    const deniedPage = await restrictedCtx.request.get(abs('/workspace/capabilities'));
    const deniedCatalog = await restrictedCtx.request.get(abs('/api/v1/capabilities'));
    assert(deniedPage.status() === 403 && deniedCatalog.status() === 403,
      'S2-N: buyer can read restricted Capability inventory/UI');
    add('S2.restricted_buyer_inventory_and_workspace_denied', {
      role: 'buyer', html: deniedPage.status(), api: deniedCatalog.status(),
    });

    const nativeBefore = Number(sqlExecute(
      "SELECT COUNT(*) FROM tn_property_assets WHERE organization_id='default'"));
    assert(Number.isInteger(nativeBefore) && nativeBefore >= 1,
      'S2-N: native Property baseline invalid');
    const deniedMutation = await restrictedCtx.request.post(
      abs('/api/v1/capabilities/property.asset.create/invoke'), {
        data: { type_code: 'house', city: 'Львів', address: 'UX00 forbidden ' + uid },
        headers: {
          'X-CSRF-Token': buyerToken, 'X-Idempotency-Key': 'ux00-restricted-' + uid,
        },
        maxRedirects: 0,
      });
    const deniedResult = await deniedMutation.json();
    // /api/v1 is guarded by ROLE_MANAGER at Symfony access_control,
    // while the human Web form independently checks Domain Gateway policy.
    assert(deniedMutation.status() === 403
      && deniedResult.ok === false,
      'S2-N: buyer API mutation escaped Symfony role access control');
    const deniedForm = await restrictedCtx.request.post(
      abs('/workspace/capabilities/property/create'), {
        form: {
          csrf_token: buyerToken, idempotency_key: 'ux00-restricted-ui-' + uid,
          type_code: 'house', city: 'Львів', address: 'UX00 forbidden UI ' + uid,
        },
        maxRedirects: 0,
      });
    assert(deniedForm.status() === 303
      && (deniedForm.headers()['location'] || '').includes('notice=invalid'),
      'S2-N: buyer UI mutation did not fail closed through canonical Gateway');
    const nativeAfter = Number(sqlExecute(
      "SELECT COUNT(*) FROM tn_property_assets WHERE organization_id='default'"));
    assert(nativeAfter === nativeBefore,
      'S2-N: restricted buyer wrote Property despite authenticated denial');
    add('S2.restricted_buyer_valid_csrf_no_native_write', {
      role: 'buyer', api_status: 403, form_status: 303, native_delta: 0,
    });

    // UX03 negative UI+saved view: valid buyer session and valid CSRF do not
    // gain manager Workspace or session preference write authority.
    const deniedUx03 = await restrictedCtx.request.get(
      abs('/workspace/unified/business/property.asset'));
    const deniedSavedView = await restrictedCtx.request.post(
      abs('/workspace/unified/business/property.asset/views'), {
        form:{csrf_token:buyerToken,operation:'save',name:'Forbidden buyer view',
          q:assetId,view:'cards',sort:'relevance'},
        maxRedirects:0,
      });
    assert(deniedUx03.status()===403 && deniedSavedView.status()===403,
      'UX03: buyer role escaped Business Workspace or saved-view ACL');
    assert(Number(sqlExecute(
      "SELECT COUNT(*) FROM tn_property_assets WHERE organization_id='default'"))
      === nativeBefore,
      'UX03: denied buyer view mutated native Property rows');
    add('UX03.restricted_buyer_workspace_and_view_denied',
      {html_status:403,preference_post_status:403,native_delta:0});
  } finally {
    await restrictedCtx.close();
  }

  // UX-01 uses the existing authenticated shell and true Application-backed
  // global search; visiting it must be read-only and tenant-scoped.
  const home = await page.goto(abs('/workspace/unified?q=' + assetId),
    { waitUntil: 'networkidle' });
  assert(home?.status() === 200, 'UX01: unified Home not accessible to manager');
  assert(await page.locator('[data-cos-unified-home]').count() === 1,
    'UX01: no canonical Unified Workspace shell');
  assert(await page.locator('[data-cos-provider-workspaces] [data-cos-provider-workspace]').count() > 0,
    'UX01: no active registered Domain Workspace context');
  const sourceHit = page.locator('.cos-uw__result').filter({ hasText: assetId });
  assert(await sourceHit.count() > 0,
    'UX01: real tenant-bound Property Query not visible in Unified Home');
  assert(sqlCount(assetId) === 1,
    'UX01: read-only unified Home unexpectedly wrote native Property');
  add('UX01.real_provider_search_and_canonical_shell', { asset_id: assetId, native_delta: 0 });

  // UX-01 real responsive/dark/density UI, using the already-installed
  // Appearance Stimulus controller rather than another frontend app.
  await page.setViewportSize({width:390,height:844});
  const ux01mobile=await page.goto(abs('/workspace/unified?q='+assetId),
    {waitUntil:'networkidle'});
  assert(ux01mobile?.status()===200
    && await page.locator('[data-cos-unified-home]').count()===1,
    'UX01: mobile canonical Shell unavailable');
  const ux01Overflow=await page.evaluate(()=>Math.max(
    document.documentElement.scrollWidth,document.body.scrollWidth,
  )-document.documentElement.clientWidth);
  assert(ux01Overflow<=2,'UX01: mobile Shell overflow '+ux01Overflow);
  await page.locator('button[data-action="click->appearance#setTheme"][data-theme="dark"]').click();
  await page.waitForFunction(()=>document.documentElement.dataset.cosTheme==='dark');
  await page.locator('button[data-action="click->appearance#setDensity"][data-density="compact"]').click();
  await page.waitForFunction(()=>document.documentElement.dataset.cosDensity==='compact');
  await page.screenshot({path:evidenceDir+'/ux01-mobile-dark-compact.png',animations:'disabled'});
  add('UX01.mobile_dark_compact_real_shell',
    {viewport:'390x844',overflow:ux01Overflow,theme:'dark',density:'compact'});
  // Canonical global keyboard palette: keyboard open, input focus,
  // Escape closes and restores focus to the invoking control.
  await page.setViewportSize({width:1440,height:1000});
  const paletteTrigger=page.locator('.cos-shell__search-trigger');
  await paletteTrigger.focus();
  await page.keyboard.press('Control+k');
  const palette=page.locator('#cos-command-palette');
  assert(!(await palette.isHidden())
    && await page.locator('.cos-command__input').evaluate(
      el => el === document.activeElement),
    'UX01: global keyboard palette did not focus search input');
  await page.keyboard.press('Escape');
  assert(await palette.isHidden()
    && await paletteTrigger.evaluate(el => el === document.activeElement),
    'UX01: global palette did not close and restore focus');
  add('UX01.keyboard_palette_focus_restoration',{shortcut:'Ctrl+K',escape:true});
  const locale=page.locator('#cos-shell-locale');
  await locale.selectOption('en');
  await page.waitForFunction(()=>document.documentElement.lang==='en');
  assert((await page.locator('[data-cos-unified-home] h1').innerText()).trim()==='My Workspaces',
    'UX01: server locale changed but Unified screen stayed Ukrainian');
  assert((await page.locator('[data-cos-provider-workspaces]').innerText())
    .includes('Property Workspace'),'UX01: Domain Workspace label not localized');
  await page.locator('#cos-shell-locale').selectOption('uk');
  await page.waitForFunction(()=>document.documentElement.lang==='uk');
  assert((await page.locator('[data-cos-unified-home] h1').innerText()).trim()==='Мої робочі простори',
    'UX01: Ukrainian locale not restored');
  add('UX01.english_ukrainian_locale_real_server_switch',{locales:['en','uk']});
  await page.locator('button[data-action="click->appearance#setTheme"][data-theme="light"]').click();
  await page.waitForFunction(()=>document.documentElement.dataset.cosTheme==='light');
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' });

  // UX02: read-only typed Query through canonical Gateway and a human
  // action form that POSTs exclusively to the existing CSRF writer.
  const queryPage = await page.goto(abs('/workspace/unified/property?q=' + assetId),
    { waitUntil: 'networkidle' });
  assert(queryPage?.status() === 200, 'UX02: typed Property search page unavailable');
  assert(await page.locator('[data-cos-property-results]').count() === 1
      && (await page.locator('[data-cos-property-results]').innerText()).includes(assetId),
      'UX02: actual typed Property Query item missing');
  const detailPage = await page.goto(abs('/workspace/unified/property?reference=' + assetId),
    { waitUntil: 'networkidle' });
  assert(detailPage?.status() === 200
      && await page.locator('[data-cos-native-property="' + assetId + '"]').count() === 1,
      'UX02: tenant-bound native Property Get readback absent');
  const actionPage = await page.goto(abs('/workspace/unified/actions'), { waitUntil: 'networkidle' });
  assert(actionPage?.status() === 200
      && await page.locator('form[action="/workspace/capabilities/property/create"]').count() === 1,
      'UX02: human Action not mapped to existing canonical form');
  assert(sqlCount(assetId)===1, 'UX02: reads mutated native Property');
  add('UX02.typed_read_and_canonical_human_form', {asset_id:assetId,native_rows:1});

  // R2 through the Unified form and the EXISTING canonical human Command,
  // with genuine native Domain readback and same idempotency key replay.
  const unifiedForm = page.locator('form[action="/workspace/capabilities/property/create"]');
  const unifiedKey = await unifiedForm.locator('input[name="idempotency_key"]').inputValue();
  const unifiedAddress = 'UX02 human form '+uid;
  await unifiedForm.locator('input[name="type_code"]').fill('house');
  await unifiedForm.locator('input[name="city"]').fill('Львів');
  await unifiedForm.locator('input[name="address"]').fill(unifiedAddress);
  await Promise.all([
    page.waitForURL(url => url.pathname==='/workspace/unified/property'
      && url.searchParams.has('reference'),{timeout:30000}),
    unifiedForm.locator('button[type="submit"]').click(),
  ]);
  const unifiedAssetId=new URL(page.url()).searchParams.get('reference')||'';
  assert(/^PROP-[A-F0-9]{20}$/.test(unifiedAssetId)
    && await page.locator('[data-cos-native-property="'+unifiedAssetId+'"]').count()===1
    && sqlCount(unifiedAssetId)===1,
    'UX02: human Command lacked verified native Domain readback');
  const unifiedReplay=await admin.request.post(
    abs('/workspace/capabilities/property/create'),{
      form:{csrf_token:csrf,idempotency_key:unifiedKey,return_to:'unified',
        type_code:'house',city:'Львів',address:unifiedAddress},maxRedirects:0,
    });
  assert(unifiedReplay.status()===303
    && new URL(unifiedReplay.headers()['location']||'',base)
       .searchParams.get('reference')===unifiedAssetId
    && sqlCount(unifiedAssetId)===1,
    'UX02: same key replay duplicated or lost Property reference');
  add('UX02.human_unified_form_native_receipt_and_replay',
    {asset_id:unifiedAssetId,native_rows:1});
  await page.locator('#cos-shell-locale').selectOption('en');
  await page.waitForFunction(()=>document.documentElement.lang==='en');
  assert((await page.locator('[data-cos-unified-property-query] h1').innerText())
      .trim()==='Find a property',
      'UX02: actual Property Query page not localized EN');
  await page.locator('#cos-shell-locale').selectOption('uk');
  await page.waitForFunction(()=>document.documentElement.lang==='uk');
  add('UX02.english_property_query_and_restore_uk',{locales:['en','uk']});
  await page.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' });

  // UX-03: registered provider composed Sales and Property, no entity
  // accepted from client until a tenant-bound SearchResult proves ownership.
  const business = await page.goto(abs('/workspace/unified/business/property.asset?q=' + assetId),
    { waitUntil: 'networkidle' });
  assert(business?.status() === 200
      && await page.locator('[data-cos-unified-business][data-workspace-id="property.asset"]').count() === 1,
      'UX03: Property provider composition unavailable');
  const nativeEntity = page.locator('[data-entity-ref="property.asset:' + assetId + '"]');
  assert(await nativeEntity.count() === 1,
      'UX03: canonical tenant-bound Property EntityRef missing');
  const selectedBusiness = await page.goto(abs('/workspace/unified/business/property.asset?q='
      + assetId + '&selected=property.asset:' + assetId), { waitUntil: 'networkidle' });
  assert(selectedBusiness?.status() === 200
      && await page.locator('[data-cos-selected-entity="property.asset:' + assetId + '"]').count() === 1,
      'UX03: verified Property entity detail cannot compose');
  assert(await page.locator('[data-cos-selected-entity] [data-cos-business-summary] .cos-entity-summary__item').count()>0,
    'UX03: selected real Property Asset has no verified canonical summary');
  add('UX03.real_tenant_property_entity_summary',{asset_id:assetId});
  const foreignBusiness = await page.goto(abs('/workspace/unified/business/property.asset?q='
      + assetId + '&selected=property.asset:' + foreignAssetId), { waitUntil: 'networkidle' });
  assert(foreignBusiness?.status() === 200
      && await page.locator('[data-cos-selected-entity]').count() === 0
      && await page.locator('[role="alert"]').count() > 0,
      'UX03: unverified foreign Property EntityRef displayed');
  const salesBusiness = await page.goto(abs('/workspace/unified/business/sales.deal'),
    { waitUntil: 'networkidle' });
  assert(salesBusiness?.status() === 200
      && await page.locator('[data-cos-unified-business][data-workspace-id="sales.deal"]').count() === 1,
      'UX03: Sales Workspace provider not available');
  assert(sqlCount(assetId) === 1, 'UX03: Workspace reads mutated native Property');
  add('UX03.sales_property_composition_and_entity_fencing', {asset_id:assetId,foreign_asset:foreignAssetId});

  // Positive Sales deal readback uses an existing real pipeline fixture.
  // The canonical Sales read model, not client-provided entity IDs, must
  // resolve it before Workspace composition and Business Summary.
  const pipelineRead=await page.goto(abs('/sales/pipeline'),{waitUntil:'networkidle'});
  assert(pipelineRead?.status()===200,'UX03: Sales pipeline read unavailable');
  const firstDeal=page.locator('[data-sales-deal-card]').first();
  assert(await firstDeal.count()===1,'UX03: Sales fixture has no canonical Deal');
  const salesId=await firstDeal.getAttribute('data-deal-id');
  const salesTitle=(await firstDeal.locator('.cos-sales-pipeline-card__subtitle').innerText()).trim();
  assert(/^[1-9][0-9]{0,9}$/.test(salesId||'') && salesTitle.length>0,
    'UX03: Sales fixture lacks verified Deal search identity');
  const salesQuery=encodeURIComponent(salesTitle.slice(0,120));
  const salesList=await page.goto(abs('/workspace/unified/business/sales.deal?q='+salesQuery),
    {waitUntil:'networkidle'});
  assert(salesList?.status()===200
    && await page.locator('[data-entity-ref="sales.deal:'+salesId+'"]').count()===1,
    'UX03: actual Sales Deal is not discoverable through tenant Domain search');
  const selectedSales=await page.goto(abs(
    '/workspace/unified/business/sales.deal?q='+salesQuery+'&selected=sales.deal:'+salesId),
    {waitUntil:'networkidle'});
  assert(selectedSales?.status()===200
    && await page.locator('[data-cos-selected-entity="sales.deal:'+salesId+'"]').count()===1
    && await page.locator('[data-cos-business-summary] .cos-entity-summary__item').count()>0,
    'UX03: real Sales Deal fails verified detail and summary');
  add('UX03.real_sales_deal_query_context_and_summary',{deal_id:salesId});


  // UX-03 server-session user filter state: same-name replay is idempotent,
  // no new Domain/Action/Asset mutation, deletion scoped to current session.
  const savedName='UX03 CI '+uid;
  const saveFirst=await admin.request.post(
    abs('/workspace/unified/business/property.asset/views'), {
      form:{csrf_token:csrf,operation:'save',name:savedName,q:assetId,
        view:'cards',sort:'label'},maxRedirects:0,
    });
  assert(saveFirst.status()===303,'UX03 saved view refused authorized CSRF');
  const reopened=await page.goto(abs('/workspace/unified/business/property.asset'),
    {waitUntil:'networkidle'});
  assert(reopened?.status()===200
    && await page.locator('[data-cos-saved-views] [data-cos-saved-view]').count()===1,
    'UX03 persisted session view not readable');
  const saved=page.locator('[data-cos-saved-view]').first();
  const id=await saved.getAttribute('data-cos-saved-view');
  assert(/^[a-f0-9]{16}$/.test(id||''),'UX03 invalid stable saved view ID');
  const replayView=await admin.request.post(
    abs('/workspace/unified/business/property.asset/views'), {
      form:{csrf_token:csrf,operation:'save',name:savedName,q:assetId,
        view:'cards',sort:'label'},maxRedirects:0,
    });
  assert(replayView.status()===303,'UX03 saved view replay failed');
  await page.reload({waitUntil:'networkidle'});
  assert(await page.locator('[data-cos-saved-view]').count()===1,
    'UX03 duplicate saved view produced on replay');
  add('UX03.saved_query_session_scoped_replay',{id,number:1});
  const deleteView=await admin.request.post(
    abs('/workspace/unified/business/property.asset/views'), {
      form:{csrf_token:csrf,operation:'delete',view_id:id},maxRedirects:0,
    });
  assert(deleteView.status()===303,'UX03 saved view delete refused');
  await page.reload({waitUntil:'networkidle'});
  assert(await page.locator('[data-cos-saved-view]').count()===0
    && sqlCount(assetId)===1,'UX03 delete leaked view or touched business Domain');
  add('UX03.saved_query_remove_no_domain_write',{native_rows:1});
  // UX-03 translated domain labels from provider names and UI-only content.
  await page.locator('#cos-shell-locale').selectOption('en');
  await page.waitForFunction(()=>document.documentElement.lang==='en');
  assert((await page.locator('[data-cos-unified-business] h1').innerText()).trim()
      ==='Property Workspace','UX03: Workspace heading not localized in English');
  assert((await page.locator('#cos-uw-business-search').innerText()).trim()
      ==='Records and search','UX03: business query controls not translated');
  await page.locator('#cos-shell-locale').selectOption('uk');
  await page.waitForFunction(()=>document.documentElement.lang==='uk');
  add('UX03.business_workspace_uk_en_locale',{locales:['en','uk']});

  // UX-03 mobile parity: real Property and Sales workspaces at phone width,
  // no horizontal document clipping and no implicit Action/Domain mutation.
  await page.setViewportSize({ width: 390, height: 844 });
  const mobileBusiness = await page.goto(abs('/workspace/unified/business/property.asset?q='
      + assetId + '&view=cards'), { waitUntil: 'networkidle' });
  assert(mobileBusiness?.status()===200
      && await page.locator('[data-cos-business-results] [data-entity-ref="property.asset:'
      +assetId+'"]').count()===1, 'UX03 mobile: actual Property card unavailable');
  const mobileOverflow = await page.evaluate(() => Math.max(
    document.documentElement.scrollWidth, document.body.scrollWidth,
  ) - document.documentElement.clientWidth);
  assert(mobileOverflow<=2, 'UX03 mobile: horizontal viewport overflow '+mobileOverflow);
  await page.screenshot({path:evidenceDir+'/ux03-property-mobile.png',
    animations:'disabled'});
  add('UX03.mobile_property_card_and_responsive_layout',
      {asset_id:assetId,viewport:'390x844',document_overflow:mobileOverflow});
  const mobileSales = await page.goto(abs('/workspace/unified/business/sales.deal'),
    {waitUntil:'networkidle'});
  assert(mobileSales?.status()===200
      && await page.locator('[data-workspace-id="sales.deal"]').count()===1,
      'UX03 mobile: Sales provider workspace unavailable');
  await page.screenshot({path:evidenceDir+'/ux03-sales-mobile.png',
    animations:'disabled'});
  assert(sqlCount(assetId)===1,'UX03 mobile: read mutated Property');
  add('UX03.mobile_sales_provider_no_native_write',{viewport:'390x844',native_rows:1});
  // Accessibility + navigation performance on genuine authenticated
  // Unified pages, in the same 390px browser environment. Results are
  // recorded independently per surface to prevent a decorative CI PASS.
  const uxA11y=[
    {id:'home',path:'/workspace/unified'},
    {id:'actions',path:'/workspace/unified/actions'},
    {id:'property',path:'/workspace/unified/property?q='+assetId},
    {id:'business',path:'/workspace/unified/business/property.asset?q='+assetId},
  ];
  for(const target of uxA11y) {
    const res=await page.goto(abs(target.path),{waitUntil:'networkidle'});
    assert(res?.status()===200,'UX03 a11y page missing '+target.id);
    const scan=await new AxeBuilder({page}).withTags(
      ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']).analyze();
    const critical=scan.violations.filter(v=>['critical','serious'].includes(v.impact));
    assert(critical.length===0,'UX03 '+target.id+' WCAG serious violations: '
      +JSON.stringify(critical.map(v=>({
        id:v.id,impact:v.impact,
        nodes:v.nodes.slice(0,4).map(n=>({target:n.target,reason:n.failureSummary}))
      }))));
    const duration=await page.evaluate(
      ()=>performance.getEntriesByType('navigation')[0]?.duration ?? 0);
    assert(Number.isFinite(duration)&&duration>=0&&duration<15000,
      'UX03 '+target.id+' navigation exceeded 15s basic performance guard');
    add('UX03.a11y_and_navigation_'+target.id,{
      viewport:'390x844',serious_violations:0,nav_duration_ms:Math.round(duration),
    });
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(abs('/workspace/capabilities'), { waitUntil: 'networkidle' });

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
  const anonymous = await browser.newContext();
  try {
    const anonymousBatch = await anonymous.request.get(abs('/api/v1/property/bulk/' + batchId));
    assert([401, 403, 404].includes(anonymousBatch.status()),
      'R3: unauthenticated client can read Property batch');
  } finally {
    await anonymous.close();
  }
  add('R3.browser_csv_stage_preview_guest_denial', { batch_id: batchId, items: 2 });

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

  // S4. One and the same real Goal/Plan must remain truthful in each mode.
  // Changing the presentation mode is NEVER a command/admission or an
  // implicit approval; verify persisted native/Action/Run cardinality.
  const goalId = planned.goal_id;
  const planActionsSql = "SELECT COUNT(*) FROM cos_actions WHERE organization_id='default'"
    + " AND target_id='" + planId + "'";
  const planRunsSql = "SELECT COUNT(*) FROM cos_workflow_runs WHERE organization_id='default'"
    + " AND source_plan_id='" + planId + "'";
  const initialActions = Number(sqlExecute(planActionsSql));
  const initialRuns = Number(sqlExecute(planRunsSql));
  assert(initialActions === 0 && initialRuns === 0,
    'S4: unapproved Property Goal already has an Action or canonical Run');

  const blockerUrl = new URL(earlyStart.headers()['location'] || '', base);
  const blockerCode = blockerUrl.searchParams.get('blocker') || '';
  assert(/^[a-z_]+$/.test(blockerCode), 'S4: missing safe typed denial code');
  assert((await page.goto(blockerUrl.toString(), { waitUntil: 'networkidle' }))?.status() === 200,
    'S4: Goals cannot render a real denial');
  await page.locator('[data-goal-blocker="' + blockerCode + '"]')
    .waitFor({ state: 'visible', timeout: 15000 });
  add('S4.real_workflow_blocker_rendered', { blocker_code: blockerCode });

  for (const mode of ['result', 'process', 'expert']) {
    const button = page.locator('form[action="/workspace/goals/mode"] button[name="mode"][value="' + mode + '"]');
    assert(await button.count() === 1, 'S4: missing mode button ' + mode);
    const [post, rendered] = await Promise.all([
      page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname === '/workspace/goals/mode' && response.request().method() === 'POST';
      }),
      page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname === '/workspace/goals' && response.request().method() === 'GET';
      }),
      button.click(),
    ]);
    assert(post.status() === 303 && rendered.status() === 200,
      'S4: mode ' + mode + ' failed: POST ' + post.status() + ' GET ' + rendered.status());
    // HTTP response headers precede the browser's document swap. Ensure the
    // redirected document is stable before inspecting and capturing evidence.
    await page.waitForLoadState('networkidle');
    const surface = page.locator('[data-cos-federation-goals][data-experience-mode="' + mode + '"]');
    await surface.waitFor({ state: 'visible', timeout: 15000 });
    const goal = surface.locator('[data-goal-id="' + goalId + '"]');
    const plan = goal.locator('[data-goal-plan="' + planId + '"]');
    await plan.waitFor({ state: 'visible', timeout: 15000 });
    assert((await plan.innerText()).includes('Очікує погодження'),
      'S4: unapproved Plan silently changed state in ' + mode + ' mode');
    assert((await plan.innerText()).includes('Імпорт об’єктів нерухомості'),
      'S4: human business operation missing from Plan in ' + mode + ' mode');
    assert((await plan.innerText()).includes('property.bulk.create') === (mode === 'expert'),
      'S4: machine capability disclosed outside Expert');
    assert(await plan.locator('form[action="/workspace/goals/plans/' + planId
      + '/request-approval"]').count() === 1,
      'S4: independent approval entry missing in ' + mode + ' mode');
    assert((await goal.locator('[data-goal-expert]').count() > 0) === (mode === 'expert'),
      'S4: Expert-only diagnostics incorrectly disclosed/hidden in ' + mode + ' mode');
    // A page screenshot is stable even if Stimulus replaces a Goal card while
    // applying progressive enhancement; a detached-element screenshot is not.
    await page.screenshot({ path: evidenceDir + '/goal-' + mode + '.png',
      fullPage: true, animations: 'disabled' });
    add('S4.' + mode + '_same_goal_pending_plan', { goal_id: goalId, plan_id: planId });
  }

  const afterModes = await json(await admin.request.get(abs('/api/v1/property/bulk/' + batchId)),
    'S4 native batch read after mode changes');
  assert(afterModes.state === 'planned' && afterModes.plan_id === planId
    && afterModes.items.every(item => item.native_asset_id === null)
    && Number(sqlExecute(planActionsSql)) === initialActions
    && Number(sqlExecute(planRunsSql)) === initialRuns,
    'S4: reading/changing Experience modes mutated a Plan, Action, Run or native Property');
  add('S4.modes_cannot_execute_or_mutate', {
    batch_id: batchId, goal_id: goalId, plan_id: planId,
    plan_actions: initialActions, workflow_runs: initialRuns,
    native_writes: 0,
  });

  // UX-07: real Goal draft creation, semantic metric choice and replay fence.
  {
  await page.setViewportSize({ width: 390, height: 844 });
  const modeForm = page.locator('form[action="/workspace/goals/mode"]');
  await Promise.all([
    page.waitForResponse(r => new URL(r.url()).pathname === '/workspace/goals/mode'
      && r.request().method() === 'POST'),
    page.waitForResponse(r => new URL(r.url()).pathname === '/workspace/goals'
      && r.request().method() === 'GET'),
    modeForm.locator('button[value="result"]').click(),
  ]);
  await page.waitForLoadState('networkidle');
  await page.locator('[data-cos-federation-goals][data-experience-mode="result"]').waitFor({state:'visible'});
  const draftForm = page.locator('form[action="/workspace/goals/create"]');
  await draftForm.locator('xpath=ancestor::details/summary').click();
  const picker = draftForm.locator('[data-goal-metric-picker]');
  assert(await picker.locator('input[name="criterion"][type="radio"]').count() >= 1,
    'UX07: no human metric picker');
  assert(await picker.locator('input[name="criterion"]:not([type="radio"])').count() === 0
    && !(await picker.innerText()).includes('sales.local_tasks_created'),
    'UX07: ordinary user must not enter machine metric code');
  await draftForm.locator('textarea[name="desired_result"]').fill('UX07 CRM tasks ' + uid);
  await picker.locator('[value="sales.local_tasks_created"]').check();
  await draftForm.locator('input[name="target"]').fill('1');
  const date = '2099-11-27';
  await draftForm.locator('input[name="deadline"]').fill(date);
  const goalDraftKey = await draftForm.locator('input[name="draft_key"]').inputValue();
  const payload = {csrf_token:csrf, draft_key:goalDraftKey, desired_result:'UX07 CRM tasks '+uid,
    criterion:'sales.local_tasks_created', target:'1', deadline:date};
  const deniedDraft = await admin.request.post(abs('/workspace/goals/create'), {
    form:{...payload, csrf_token:'invalid'}, maxRedirects:0,
  });
  assert(deniedDraft.status() === 400, 'UX07: draft creation accepted invalid CSRF');
  const unknownMetric = await admin.request.post(abs('/workspace/goals/create'), {
    form:{...payload, criterion:'fake.metric'}, maxRedirects:0,
  });
  assert(unknownMetric.status() === 303 && (unknownMetric.headers()['location'] || '').includes('error=invalid_input'),
    'UX07: arbitrary metric accepted');
  await page.screenshot({path:evidenceDir+'/goals-metric-picker-mobile.png', fullPage:true, animations:'disabled'});
  const goalScan = await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();
  assert(goalScan.violations.filter(v=>['critical','serious'].includes(v.impact)).length===0,
    'UX07: critical/serious accessibility violation');
  const actionsBeforeDraft = Number(sqlExecute("SELECT COUNT(*) FROM cos_actions WHERE organization_id='default'"));
  const runsBeforeDraft = Number(sqlExecute("SELECT COUNT(*) FROM cos_workflow_runs WHERE organization_id='default'"));
  const saved = await admin.request.post(abs('/workspace/goals/create'), {form:payload,maxRedirects:0});
  const replay = await admin.request.post(abs('/workspace/goals/create'), {form:payload,maxRedirects:0});
  assert([saved,replay].every(r=>r.status()===303 && (r.headers()['location']||'').includes('created=1')),
    'UX07: valid draft or idempotent replay failed');
  const draftCount = Number(sqlExecute("SELECT COUNT(*) FROM cos_federation_goal_specs WHERE organization_id='default' AND JSON_UNQUOTE(JSON_EXTRACT(specification,'$.desired_result'))='UX07 CRM tasks "+uid+"'"));
  assert(draftCount === 1 && Number(sqlExecute("SELECT COUNT(*) FROM cos_actions WHERE organization_id='default'"))===actionsBeforeDraft
    && Number(sqlExecute("SELECT COUNT(*) FROM cos_workflow_runs WHERE organization_id='default'"))===runsBeforeDraft,
    'UX07: draft replay duplicated a Goal or performed a side effect');
  await page.goto(abs('/workspace/goals'), {waitUntil:'networkidle'});
  const renderedDraft=page.locator('[data-goal-id]').filter({hasText:'UX07 CRM tasks '+uid});
  assert(await renderedDraft.count()===1 && (await renderedDraft.innerText()).includes(date),
    'UX07: persisted draft or deadline missing');
  add('UX07.semantic_picker_draft_replay_and_mobile',{goal_drafts:1,actions_created:0,runs_created:0});
  await page.setViewportSize({width:1440,height:1000});

  }

  // AC-08: The operation must be bookmarkable; pending items are not
  // announced as verified native assets until independent receipts exist.
  const pendingPage = await page.goto(abs('/workspace/property/bulk?batch=' + batchId),
    { waitUntil: 'networkidle' });
  assert(pendingPage?.status() === 200, 'R3: deep-linked batch is not readable');
  const operation = page.locator('[data-property-bulk-operation="' + batchId + '"]');
  await operation.waitFor({ state: 'visible', timeout: 15000 });
  assert(await operation.locator('[data-property-bulk-progress="0/2"]').count() === 1,
    'R3: pending batch progress incorrectly reports verified Assets');
  assert(await operation.locator('[data-property-bulk-item]').count() === 2,
    'R3: per-item status cardinality differs from staged CSV');
  assert(await operation.locator('[data-property-bulk-item-verified]').count() === 0
    && await operation.locator('[data-property-bulk-item-pending]').count() === 2
    && await operation.locator('[data-property-bulk-verified]').count() === 0
    && await operation.locator('[data-property-bulk-awaiting-verification]').count() === 1,
  'R3: pending batch falsely claims native per-item verification');
  const deepLink = await operation.locator('[data-property-bulk-deep-link]').getAttribute('href');
  assert(deepLink === '/workspace/property/bulk?batch=' + batchId,
    'R3: operation deep link was not stable and tenant-scoped');
  add('R3.pending_deep_link_and_per_item_truth', {
    batch_id: batchId, items: 2, verified: 0, deep_link: deepLink,
  });

  // S3-M: a narrow-screen operator can reopen a pending batch, inspect
  // both native item statuses and navigate its canonical, tenant-scoped link.
  await page.setViewportSize({ width: 390, height: 844 });
  const mobileBatch = await page.goto(abs('/workspace/property/bulk?batch=' + batchId),
    { waitUntil: 'networkidle' });
  assert(mobileBatch?.status() === 200, 'S3-M: Property Bulk mobile detail unavailable');
  const mobileOperation = page.locator('[data-property-bulk-operation="' + batchId + '"]');
  await mobileOperation.waitFor({ state: 'visible', timeout: 15000 });
  assert(await mobileOperation.locator('[data-property-bulk-item-pending]').count() === 2
    && await mobileOperation.locator('[data-property-bulk-item-verified]').count() === 0,
    'S3-M: mobile view fabricated success or lost item details');
  const mobileLink = mobileOperation.locator('[data-property-bulk-deep-link]');
  assert(await mobileLink.isVisible(), 'S3-M: persistent operation link hidden on mobile');
  await page.screenshot({ path: evidenceDir + '/property-mobile-pending.png',
    animations: 'disabled' });
  await mobileLink.click();
  await page.waitForLoadState('networkidle');
  const mobileUrl = new URL(page.url());
  assert(mobileUrl.pathname === '/workspace/property/bulk'
    && mobileUrl.searchParams.get('batch') === batchId
    && await page.locator('[data-property-bulk-item-pending]').count() === 2,
    'S3-M: mobile batch link lost persisted item readback');
  add('S3.mobile_pending_items_and_deep_link', {
    batch_id: batchId, viewport: '390x844', pending: 2, verified: 0,
  });
  await page.setViewportSize({ width: 1440, height: 1000 });

  // R3-P: real two-user Plan approval through the canonical UI + Policy
  // + Approval queue + Federation Action handler. No external Property Action
  // permission is enabled; the native batch must stay at zero verified.
  const reviewerEmail = process.env.UX00_E2E_REVIEWER_EMAIL || '';
  const reviewerPassword = process.env.UX00_E2E_REVIEWER_PASSWORD || '';
  assert(reviewerEmail && reviewerPassword, 'R3-P needs independent CI manager identity');
  const reviewerCtx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  try {
    const reviewerPage = await login(reviewerCtx, reviewerEmail, reviewerPassword);
    assert((await page.goto(abs('/workspace/goals'), { waitUntil: 'networkidle' }))?.status()===200,
      'R3-P: Goal owner cannot open Approval workspace');
    const planApprovalForm = page.locator('form[action="/workspace/goals/plans/' + planId
      + '/request-approval"]');
    assert(await planApprovalForm.count()===1, 'R3-P: Plan approval request UI missing');
    await Promise.all([
      page.waitForURL(url => url.pathname === '/workspace/goals'
        && url.searchParams.get('notice') === 'approval_requested', { timeout: 30000 }),
      planApprovalForm.locator('button[type="submit"]').click(),
    ]);
    add('R3.plan_owner_requested_real_policy_approval', { plan_id:planId });

    const actionResult = sqlExecute("SELECT CONCAT(id,'|',status) FROM cos_actions "
      + "WHERE organization_id='default' AND type='cos.federation.plan.approval' "
      + "AND target_id='" + planId + "'");
    const actionParts = actionResult.split('|');
    assert(actionParts.length===2 && /^[a-f0-9]{32}$/.test(actionParts[0])
      && actionParts[1]==='PENDING_APPROVAL',
      'R3-P: Policy did not produce one pending canonical Plan Action: ' + actionResult);
    const planActionId=actionParts[0];
    const approvalResult=sqlExecute("SELECT CONCAT(id,'|',status) FROM cos_approvals "
      + "WHERE organization_id='default' AND action_id='" + planActionId + "'");
    const approvalParts=approvalResult.split('|');
    assert(approvalParts.length===2 && /^[a-f0-9]{32}$/.test(approvalParts[0])
      && approvalParts[1]==='PENDING',
      'R3-P: no single pending human approval record: ' + approvalResult);
    const approvalId=approvalParts[0];
    add('R3.plan_policy_pending_human_approval', {action_id:planActionId, approval_id:approvalId});

    // Owner cannot self-approve. The database MUST remain pending.
    const ownerControl=await page.goto(abs('/cos/control-center#approval-' + approvalId),
      {waitUntil:'networkidle'});
    assert(ownerControl?.status()===200,'R3-P: owner Control Center unavailable');
    const ownerApprove=page.locator('form[action="/cos/approval/' + approvalId + '/approve"]');
    assert(await ownerApprove.count()===1,'R3-P: canonical owner approval control absent');
    await Promise.all([
      page.waitForURL(url=>url.pathname==='/cos/control-center'
        && url.searchParams.has('status_message'),{timeout:30000}),
      ownerApprove.locator('button[type="submit"]').click(),
    ]);
    assert(sqlExecute("SELECT status FROM cos_approvals WHERE organization_id='default' AND id='"
      +approvalId+"'")==='PENDING',
      'R3-P: owner self-approved independent Plan');
    add('R3.plan_owner_self_approval_denied', {approval_id:approvalId});

    // Authenticated second manager, same organization, distinct user/session.
    const control=await reviewerPage.goto(abs('/cos/control-center#approval-'+approvalId),
      {waitUntil:'networkidle'});
    assert(control?.status()===200,'R3-P: independent reviewer cannot open Control Center');
    const independentApprove=reviewerPage.locator('form[action="/cos/approval/'
      +approvalId+'/approve"]');
    assert(await independentApprove.count()===1,
      'R3-P: independent reviewer cannot access the Approval');
    await Promise.all([
      reviewerPage.waitForURL(url=>url.pathname==='/cos/control-center'
        && url.searchParams.get('status_message')==='APPROVED',{timeout:30000}),
      independentApprove.locator('button[type="submit"]').click(),
    ]);
    const reviewEvidence=sqlExecute("SELECT CONCAT(status,'|',decided_by_type,'|',decided_by_id) "
      +"FROM cos_approvals WHERE organization_id='default' AND id='"+approvalId+"'");
    const reviewerId=sqlExecute("SELECT id FROM tn_users WHERE organization_id='default' AND email='"
      +reviewerEmail+"'");
    assert(reviewEvidence==='APPROVED|USER|'+reviewerId,
      'R3-P: canonical Approval did not record independent manager: '+reviewEvidence);
    add('R3.independent_manager_approved_via_web',{approval_id:approvalId});

    // The existing canonical queue worker, not a direct handler/SQL update.
    const queueOutput=execFileSync('docker',[
      'compose','exec','-T','php','php','bin/console','cos:queue:run',
      '--worker-id=ux00-plan-'+uid,'--limit=20',
    ],{encoding:'utf8',timeout:30000});
    assert(queueOutput.includes('"processed"'), 'R3-P: canonical queue worker failed: '+queueOutput);
    const finalAction=sqlExecute("SELECT status FROM cos_actions WHERE organization_id='default'"
      +" AND id='"+planActionId+"'");
    const finalPlan=sqlExecute("SELECT state FROM cos_federation_plans WHERE organization_id='default'"
      +" AND plan_id='"+planId+"'");
    assert(finalAction==='COMPLETED' && finalPlan==='approved',
      'R3-P: trusted Plan handler did not complete: Action='+finalAction+' Plan='+finalPlan);
    assert(Number(sqlExecute("SELECT COUNT(*) FROM cos_action_attempts WHERE organization_id='default'"
      +" AND action_id='"+planActionId+"'"))===1,
      'R3-P: duplicate or absent Action receipt attempt');
    add('R3.canonical_plan_worker_receipt_completed',
      {action_id:planActionId,plan_id:planId,attempts:1});

    // Current deployment intentionally has *no* external Workflow admission.
    const noRun=await admin.request.post(abs('/workspace/goals/plans/'+planId+'/start'),{
      form:{csrf_token:csrf},maxRedirects:0,
    });
    assert(noRun.status()===303 && (noRun.headers()['location']||'').includes('blocker='),
      'R3-P: non-allowlisted Property Workflow began from an approved Plan');
    const nativePending=await json(await admin.request.get(abs('/api/v1/property/bulk/'+batchId)),
      'R3-P immutable native Property readback');
    assert(nativePending.state==='planned'
      && nativePending.items.every(item=>item.native_asset_id===null)
      && Number(sqlExecute(planRunsSql))===0,
      'R3-P: unadmitted external Property writes escaped after Plan approval');
    add('R3.approved_plan_external_workflow_still_fail_closed',
      {plan_id:planId,run_count:0,native_writes:0});
  } finally {
    await reviewerCtx.close();
  }

  const report = { schema: 1, suite: 'COS UX-00 R1/R2/R3 browser acceptance',
    environment: 'GitHub Actions disposable localhost MySQL', run_id: uid,
    tenant_scope: 'Authenticated default tenant vs foreign-tenant native fixtures; multi-org login not offered by this deployment',
    checks, tested_at: new Date().toISOString(),
    remaining_gate: 'Buyer role denial requires exact-head CI; external Property Step approval, production fail-closed worker admission and completed Run UI staging remain unverified' };
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
