import { chromium } from 'playwright-core';

const baseUrl = process.env.COS_WEB_SMOKE_BASE_URL || 'http://127.0.0.1:8081';
const adminEmail = process.env.COS_WEB_SMOKE_EMAIL || '';
const adminPassword = process.env.COS_WEB_SMOKE_PASSWORD || '';
const executablePath = process.env.CHROME_PATH || '/usr/bin/google-chrome';
const runId = (process.env.GITHUB_RUN_ID || Date.now().toString()).replace(/[^0-9A-Za-z_-]/g, '');
const suffix = runId.slice(-12);

if (!adminEmail || !adminPassword) {
  throw new Error('COS_WEB_SMOKE_EMAIL and COS_WEB_SMOKE_PASSWORD are required.');
}

const browser = await chromium.launch({ headless: true, executablePath });
const absolute = (path) => new URL(path, baseUrl).toString();

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

async function login(context, email, password) {
  const page = await context.newPage();
  const response = await page.goto(absolute('/auth/login'), { waitUntil: 'domcontentloaded' });
  assert(response?.status() === 200, 'Login page must return 200.');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/auth/login'), { timeout: 25000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  return page;
}

async function goto200(page, path, marker = null) {
  const response = await page.goto(absolute(path), { waitUntil: 'networkidle', timeout: 30000 });
  assert(response?.status() === 200, path + ' expected HTTP 200, received ' + (response?.status() ?? 'no response'));
  if (marker) {
    await page.locator(marker).first().waitFor({ state: 'visible', timeout: 10000 });
  }
  return response;
}

async function submitAndWait(page, form, urlPredicate) {
  const action = await form.getAttribute('action');
  const method = (await form.getAttribute('method') || 'GET').toUpperCase();
  const actionUrl = new URL(action || page.url(), page.url());

  const responsePromise = page.waitForResponse((response) => {
    const responseUrl = new URL(response.url());
    return response.request().method() === method
      && responseUrl.origin === actionUrl.origin
      && responseUrl.pathname === actionUrl.pathname;
  }, { timeout: 30000 });

  await form.locator('button[type="submit"]').first().click();
  const response = await responsePromise;
  assert(
    response.status() >= 200 && response.status() < 400,
    `Form ${method} ${actionUrl.pathname} failed with HTTP ${response.status()}.`,
  );

  const location = response.headers()['location'];
  const redirectUrl = location ? new URL(location, response.url()) : null;

  await eventually(
    () => {
      const currentUrl = new URL(page.url());
      if (!urlPredicate(currentUrl)) return false;
      if (!redirectUrl) return true;
      return currentUrl.origin === redirectUrl.origin
        && currentUrl.pathname === redirectUrl.pathname
        && currentUrl.search === redirectUrl.search;
    },
    'Form navigation did not reach the expected URL'
      + (redirectUrl ? ': ' + redirectUrl.toString() : ': ' + page.url()),
    30000,
  );

  assert(urlPredicate(new URL(page.url())), 'Form navigation ended at unexpected URL: ' + page.url());
  await page.waitForLoadState('networkidle');
}

async function eventually(check, message, timeoutMs = 20000, intervalMs = 500) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      if (await check()) return;
    } catch (error) {
      lastError = error;
    }
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
  throw new Error(message + (lastError ? ': ' + lastError.message : ''));
}

async function csrfToken(page) {
  const token = await page.locator('input[name="csrf_token"]').first().inputValue();
  assert(token.length > 10, 'Authenticated page must expose a CSRF token.');
  return token;
}

const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const pageErrors = [];

try {
  const page = await login(adminContext, adminEmail, adminPassword);
  page.on('pageerror', (error) => pageErrors.push(error.stack || error.message));

  // 1. Administration Users: create -> filter -> update -> new-user login.
  await goto200(page, '/admin/users', '[data-cos-archetype]');
  const managerEmail = `functional-manager-${suffix}@example.test`;
  const managerPassword = `Functional-${suffix}-Ab9!`;
  const managerName = `Functional Manager ${suffix}`;
  const createUser = page.locator('form[action="/admin/createUser"]');
  await createUser.locator('input[name="full_name"]').fill(managerName);
  await createUser.locator('input[name="email"]').fill(managerEmail);
  await createUser.locator('input[name="phone"]').fill('+380501234567');
  await createUser.locator('input[name="password"]').fill(managerPassword);
  await createUser.locator('select[name="role"]').selectOption('manager');
  await createUser.locator('select[name="status"]').selectOption('active');
  await submitAndWait(page, createUser, (url) => url.pathname === '/admin/users' && url.searchParams.has('status_message'));
  assert((await page.locator('body').innerText()).includes('Користувача створено'), 'Admin create-user success message is missing.');

  await goto200(page, '/admin/users?q=' + encodeURIComponent(managerEmail));
  const userCard = page.locator('article.cos-card').filter({ hasText: managerEmail }).first();
  assert(await userCard.count() === 1, 'Created user is missing from filtered admin list.');
  const updateUser = userCard.locator('form[action^="/admin/updateUser/"]');
  const userAction = await updateUser.getAttribute('action');
  assert(/^\/admin\/updateUser\/[1-9][0-9]*$/.test(userAction || ''), 'Created user update action is invalid.');
  const updatedManagerName = managerName + ' Updated';
  await updateUser.locator('input[name="full_name"]').fill(updatedManagerName);
  await submitAndWait(page, updateUser, (url) => url.pathname === '/admin/users' && url.searchParams.has('status_message'));
  await goto200(page, '/admin/users?q=' + encodeURIComponent(managerEmail));
  assert((await page.locator('body').innerText()).includes(updatedManagerName), 'Updated user name did not persist after reload.');

  const managerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const managerPage = await login(managerContext, managerEmail, managerPassword);
  await goto200(managerPage, '/sales/today', '[data-cos-archetype]');
  await managerContext.close();

  // Duplicate user is handled as a readable validation result.
  await goto200(page, '/admin/users');
  const duplicateUser = page.locator('form[action="/admin/createUser"]');
  await duplicateUser.locator('input[name="full_name"]').fill(managerName);
  await duplicateUser.locator('input[name="email"]').fill(managerEmail);
  await duplicateUser.locator('input[name="password"]').fill(managerPassword);
  await duplicateUser.locator('select[name="role"]').selectOption('manager');
  await duplicateUser.locator('select[name="status"]').selectOption('active');
  await submitAndWait(page, duplicateUser, (url) => url.pathname === '/admin/users' && url.searchParams.has('status_message'));
  assert((await page.locator('body').innerText()).includes('Користувач із таким email уже існує'), 'Duplicate-user validation message is missing.');

  // 2. Client Case: create -> detail -> edit -> activity -> collection search.
  await goto200(page, '/client-case', '[data-client-case-collection]');
  const caseName = `Acceptance Client ${suffix}`;
  const caseEmail = `acceptance-client-${suffix}@example.test`;
  const createCase = page.locator('form[action="/client-case/create"]');
  await createCase.locator('input[name="full_name"]').fill(caseName);
  await createCase.locator('input[name="phone"]').fill('+380671234567');
  await createCase.locator('input[name="email"]').fill(caseEmail);
  await createCase.locator('input[name="budget_max"]').fill('125000');
  await createCase.locator('textarea[name="description"]').fill('Production cutover functional acceptance case.');
  await submitAndWait(page, createCase, (url) => /^\/client-case\/show\/[1-9][0-9]*$/.test(url.pathname));
  const caseMatch = page.url().match(/\/client-case\/show\/([1-9][0-9]*)/);
  assert(caseMatch, 'Client Case create did not navigate to dynamic detail route.');
  const caseId = caseMatch[1];
  await page.locator(`[data-client-case-workspace][data-case-id="${caseId}"]`).waitFor({ state: 'visible' });

  const updateCase = page.locator(`form[action="/client-case/update/${caseId}"]`);
  const updatedCaseTitle = `Acceptance Deal ${suffix}`;
  await updateCase.locator('input[name="title"]').fill(updatedCaseTitle);
  await updateCase.locator('select[name="priority"]').selectOption('urgent');
  await updateCase.locator('input[name="budget_min"]').fill('90000');
  await updateCase.locator('textarea[name="description"]').fill('Updated functional acceptance description.');
  await submitAndWait(page, updateCase, (url) => url.pathname === `/client-case/show/${caseId}` && url.searchParams.has('status_message'));
  assert(await page.locator(`form[action="/client-case/update/${caseId}"] input[name="title"]`).inputValue() === updatedCaseTitle, 'Client Case title did not persist.');
  assert(await page.locator(`form[action="/client-case/update/${caseId}"] select[name="priority"]`).inputValue() === 'urgent', 'Client Case priority did not persist.');

  const activityTitle = `Acceptance note ${suffix}`;
  let activity = page.locator(`form[action="/client-case/activity/${caseId}"]`);
  await activity.locator('select[name="activity_type"]').selectOption('note');
  await activity.locator('input[name="title"]').fill(activityTitle);
  await activity.locator('textarea[name="body"]').fill('Functional acceptance activity.');
  await submitAndWait(page, activity, (url) => url.pathname === `/client-case/show/${caseId}` && url.searchParams.has('status_message'));
  let activityStatus = new URL(page.url()).searchParams.get('status_message') || '';
  assert(activityStatus.startsWith('OK:'), 'Client Case note mutation failed: ' + activityStatus);
  await goto200(page, `/client-case/show/${caseId}`, '[data-client-case-workspace]');
  assert((await page.locator('body').innerText()).includes(activityTitle), 'Client Case note did not persist in timeline after canonical reload.');

  const callTitle = `Acceptance call ${suffix}`;
  activity = page.locator(`form[action="/client-case/activity/${caseId}"]`);
  await activity.locator('select[name="activity_type"]').selectOption('call');
  await activity.locator('input[name="title"]').fill(callTitle);
  await activity.locator('input[name="duration_seconds"]').fill('45');
  await activity.locator('input[name="call_result"]').fill('acceptance_ok');
  await activity.locator('textarea[name="body"]').fill('Functional completed call.');
  await activity.locator('input[name="completed"]').check();
  await submitAndWait(page, activity, (url) => url.pathname === `/client-case/show/${caseId}` && url.searchParams.has('status_message'));
  activityStatus = new URL(page.url()).searchParams.get('status_message') || '';
  assert(activityStatus.startsWith('OK:'), 'Client Case completed-call mutation failed: ' + activityStatus);
  await goto200(page, `/client-case/show/${caseId}`, '[data-client-case-workspace]');
  assert((await page.locator('body').innerText()).includes(callTitle), 'Client Case completed call did not persist in timeline after canonical reload.');

  await goto200(page, '/client-case?q=' + encodeURIComponent(caseEmail), '[data-client-case-collection]');
  const caseItem = page.locator('[data-client-case-collection-item]').filter({ has: page.locator(`a[href="/client-case/show/${caseId}"]`) }).first();
  assert(await caseItem.count() === 1, 'Client Case search by email did not return the created case id.');
  const quickUpdate = caseItem.locator(`form[action="/client-case/quickUpdate/${caseId}"]`);
  await quickUpdate.locator('select[name="priority"]').selectOption('high');
  await submitAndWait(page, quickUpdate, (url) => url.pathname === '/client-case' && url.searchParams.has('status_message'));
  await goto200(page, '/client-case?q=' + encodeURIComponent(caseEmail), '[data-client-case-collection]');
  const updatedCaseItem = page.locator('[data-client-case-collection-item]').filter({ has: page.locator(`a[href="/client-case/show/${caseId}"]`) }).first();
  assert(await updatedCaseItem.locator('select[name="priority"]').inputValue() === 'high', 'Client Case quick update did not persist.');

  // The same opportunity must render as a Sales Deal dynamic route.
  await goto200(page, `/sales/deals/${caseId}`, '[data-sales-deal-workspace]');

  // 3. Sales Lead: API fixture -> UI qualify -> UI convert -> Deal workspace -> Pipeline transition -> message.
  await goto200(page, '/client-case', '[data-client-case-collection]');
  const token = await csrfToken(page);
  const invalidLead = await page.evaluate(async ({ token }) => {
    const response = await fetch('/api/v1/sales/leads', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
        'X-Idempotency-Key': 'functional-invalid-' + Date.now(),
      },
      body: JSON.stringify({ full_name: 'Invalid Email Lead', email: 'not-an-email' }),
    });
    return { status: response.status, payload: await response.json() };
  }, { token });
  assert(invalidLead.status === 422 && invalidLead.payload?.error === 'invalid_email', 'Sales lead server validation did not reject invalid email.');

  const leadEmail = `functional-lead-${suffix}@example.test`;
  const leadResult = await page.evaluate(async ({ token, leadEmail, suffix }) => {
    const response = await fetch('/api/v1/sales/leads', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
        'X-Idempotency-Key': 'functional-lead-' + suffix,
      },
      body: JSON.stringify({
        full_name: 'Functional Lead ' + suffix,
        email: leadEmail,
        role: 'buyer',
        deal_type: 'sale',
        source: 'production-cutover-functional',
        request_intent: 'viewing',
        message: 'Functional acceptance lead',
      }),
    });
    return { status: response.status, payload: await response.json() };
  }, { token, leadEmail, suffix });
  assert([200, 201].includes(leadResult.status) && leadResult.payload?.ok === true, 'Sales lead fixture creation failed: ' + JSON.stringify(leadResult));
  const leadId = String(leadResult.payload.data?.lead_id || '');
  assert(/^[1-9][0-9]*$/.test(leadId), 'Sales lead creation did not return a numeric id.');

  await goto200(page, '/sales/leads?status=new&q=' + encodeURIComponent(leadEmail), '[data-sales-surface="lead-list"]');
  let leadCard = page.locator(`[data-lead-id="${leadId}"]`);
  assert(await leadCard.count() === 1, 'Created Sales lead is missing from Lead List.');
  const qualify = leadCard.locator('[data-sales-lead-status][data-status="qualified"]');
  const qualifyResponse = page.waitForResponse((response) => response.url().includes(`/api/v1/sales/leads/${leadId}`) && response.request().method() === 'PATCH');
  await qualify.click();
  const qr = await qualifyResponse;
  assert(qr.status() === 200, 'Qualify Lead mutation failed with ' + qr.status());
  await goto200(page, '/sales/leads?status=qualified&q=' + encodeURIComponent(leadEmail), '[data-sales-surface="lead-list"]');
  leadCard = page.locator(`[data-lead-id="${leadId}"]`);
  assert(await leadCard.count() === 1, 'Qualified Sales lead did not persist after reload.');

  const convert = leadCard.locator('[data-sales-lead-deal]');
  assert(await convert.count() === 1, 'Qualified lead is missing Create Deal action.');
  await Promise.all([
    page.waitForURL((url) => /^\/sales\/deals\/[1-9][0-9]*$/.test(url.pathname), { timeout: 30000 }),
    convert.click(),
  ]);
  await page.waitForLoadState('networkidle');
  const dealMatch = page.url().match(/\/sales\/deals\/([1-9][0-9]*)/);
  assert(dealMatch, 'Lead conversion did not navigate to Deal workspace.');
  const dealId = dealMatch[1];
  await page.locator('[data-sales-deal-workspace]').waitFor({ state: 'visible' });

  const messageBody = `Functional message ${suffix}`;
  const messageForm = page.locator('form[data-sales-operation-form][data-operation="message"]');
  if (await messageForm.count()) {
    await messageForm.locator('select[name="channel"]').selectOption('WEB');
    await messageForm.locator('textarea[name="body"]').fill(messageBody);
    const messageResponse = page.waitForResponse((response) => response.url().includes(`/api/v1/sales/opportunities/${dealId}/communications`) && response.request().method() === 'POST');
    await messageForm.locator('button').filter({ hasText: 'Send Message' }).click();
    const mr = await messageResponse;
    assert(mr.status() === 202, 'Deal message async operation expected HTTP 202, received ' + mr.status());
    await eventually(async () => {
      await goto200(page, `/sales/deals/${dealId}`, '[data-sales-deal-workspace]');
      return await page.locator('.cos-sales-message').filter({ hasText: messageBody }).count() === 1;
    }, 'Deal message was accepted but did not reach the communications projection');
  }

  await goto200(page, '/sales/pipeline', '[data-sales-pipeline-root]');
  const dealCard = page.locator(`[data-sales-deal-card][data-deal-id="${dealId}"]`);
  assert(await dealCard.count() === 1, 'Converted Deal is missing from Sales Pipeline.');
  const sourceStage = await dealCard.getAttribute('data-stage-id');
  const zones = page.locator('[data-sales-stage-dropzone]');
  let sourceIndex = -1;
  for (let i = 0; i < await zones.count(); i += 1) {
    if ((await zones.nth(i).getAttribute('data-stage-id')) === sourceStage) { sourceIndex = i; break; }
  }
  if (sourceIndex >= 0 && sourceIndex + 1 < await zones.count()) {
    const target = zones.nth(sourceIndex + 1);
    const targetStage = await target.getAttribute('data-stage-id');
    const stageResponse = page.waitForResponse((response) => response.url().includes(`/api/v1/sales/opportunities/${dealId}/stage`) && response.request().method() === 'POST');
    await dealCard.dragTo(target);
    const sr = await stageResponse;
    assert(sr.status() === 200, 'Deal stage change failed with ' + sr.status());
    await goto200(page, '/sales/pipeline', '[data-sales-pipeline-root]');
    const moved = page.locator(`[data-sales-deal-card][data-deal-id="${dealId}"]`);
    assert(await moved.getAttribute('data-stage-id') === targetStage, 'Deal stage transition did not persist.');
  }

  // 4. Public Property intake -> manager queue -> dynamic detail.
  const submissionTitle = `Functional Property ${suffix}`;
  await goto200(page, '/property/submit', '[data-cos-public="property-submit"]');
  const propertyForm = page.locator('form[action="/property/submit"]');
  await propertyForm.locator('input[name="owner_name"]').fill('Functional Owner ' + suffix);
  await propertyForm.locator('input[name="owner_phone"]').fill('+380931234567');
  await propertyForm.locator('input[name="owner_email"]').fill(`functional-owner-${suffix}@example.test`);
  await propertyForm.locator('input[name="title"]').fill(submissionTitle);
  await propertyForm.locator('select[name="deal_type"]').selectOption('sale');
  await propertyForm.locator('select[name="property_type"]').selectOption('other');
  await propertyForm.locator('input[name="city"]').fill('Lviv');
  await propertyForm.locator('input[name="region"]').fill('Lviv');
  await propertyForm.locator('input[name="price_amount"]').fill('180000');
  await propertyForm.locator('input[name="area_total"]').fill('145.5');
  await propertyForm.locator('textarea[name="description"]').fill('Functional acceptance property submission with enough detail for moderation.');
  const [propertyResponse] = await Promise.all([
    page.waitForResponse((response) =>
      new URL(response.url()).pathname === '/property/submit'
      && response.request().method() === 'POST'
    , { timeout: 30000 }),
    propertyForm.locator('button[type="submit"]').first().click(),
  ]);
  if (![302, 303].includes(propertyResponse.status())) {
    const body = await propertyResponse.text().catch(() => '');
    throw new Error('Property submission expected redirect, received ' + propertyResponse.status() + ': ' + body.slice(0, 2000));
  }
  const propertyLocation = propertyResponse.headers()['location'] || '';
  assert(propertyLocation.includes('/property/submit') && propertyLocation.includes('submitted=1'),
    'Property submission redirect Location is invalid: ' + propertyLocation);
  if (!(new URL(page.url()).searchParams.get('submitted') === '1')) {
    await goto200(page, propertyLocation);
  } else {
    await page.waitForLoadState('networkidle');
  }
  assert((await page.locator('body').innerText()).includes('Заявку прийнято'), 'Property submission success state is missing.');

  await goto200(page, '/property/submissions', '[data-property-submissions]');
  const submissionLink = page.locator('a[href^="/property/submission/"]').filter({ hasText: submissionTitle }).first();
  assert(await submissionLink.count() === 1, 'Created Property submission is missing from moderation queue.');
  const submissionHref = await submissionLink.getAttribute('href');
  assert(/^\/property\/submission\/[1-9][0-9]*$/.test(submissionHref || ''), 'Property submission dynamic detail link is invalid.');
  await goto200(page, submissionHref, '[data-property-submission]');
  assert((await page.locator('body').innerText()).includes(submissionTitle), 'Property submission detail does not contain submitted title.');

  // 5. Spatial: validation -> create -> edit -> capture -> hotspot -> search -> publish failure state.
  await goto200(page, '/spatial/edit', 'form[action^="/spatial/save"]');
  let spatialForm = page.locator('form[action^="/spatial/save"]').first();
  const sceneTitle = `Functional Spatial ${suffix}`;
  await spatialForm.locator('input[name="title"]').fill(sceneTitle);
  await spatialForm.locator('select[name="viewer_type"]').selectOption('external');
  const [spatialValidationResponse] = await Promise.all([
    page.waitForResponse((response) =>
      response.url().includes('/spatial/save')
      && response.request().method() === 'POST'
    , { timeout: 30000 }),
    spatialForm.locator('button[type="submit"]').first().click(),
  ]);
  assert([302, 303].includes(spatialValidationResponse.status()),
    'Spatial validation expected redirect, received ' + spatialValidationResponse.status());
  const spatialValidationLocation = spatialValidationResponse.headers()['location'] || '';
  assert(spatialValidationLocation.includes('/spatial/edit') && spatialValidationLocation.includes('status_message='),
    'Spatial validation redirect Location is invalid: ' + spatialValidationLocation);
  await goto200(page, spatialValidationLocation);
  assert((await page.locator('body').innerText()).includes('потрібен URL'), 'Spatial external-viewer validation message is missing.');

  spatialForm = page.locator('form[action^="/spatial/save"]').first();
  await spatialForm.locator('input[name="title"]').fill(sceneTitle);
  await spatialForm.locator('select[name="viewer_type"]').selectOption('threejs');
  await spatialForm.locator('textarea[name="default_camera_json"]').fill('{}');
  await spatialForm.locator('textarea[name="settings_json"]').fill('{}');
  await submitAndWait(page, spatialForm, (url) => /^\/spatial\/edit\/[1-9][0-9]*$/.test(url.pathname));
  const sceneMatch = page.url().match(/\/spatial\/edit\/([1-9][0-9]*)/);
  assert(sceneMatch, 'Spatial create did not navigate to dynamic edit route.');
  const sceneId = sceneMatch[1];

  spatialForm = page.locator(`form[action="/spatial/save/${sceneId}"]`);
  const updatedSceneTitle = sceneTitle + ' Updated';
  await spatialForm.locator('input[name="title"]').fill(updatedSceneTitle);
  await spatialForm.locator('textarea[name="description"]').fill('Spatial functional acceptance description.');
  await submitAndWait(page, spatialForm, (url) => url.pathname === `/spatial/edit/${sceneId}` && url.searchParams.has('status_message'));
  assert(await page.locator(`form[action="/spatial/save/${sceneId}"] input[name="title"]`).inputValue() === updatedSceneTitle, 'Spatial edit did not persist.');

  const captureForm = page.locator(`form[action="/spatial/capture/${sceneId}"]`);
  const captureLabel = `Acceptance capture ${suffix}`;
  await captureForm.locator('select[name="capture_type"]').selectOption('manual');
  await captureForm.locator('input[name="label"]').fill(captureLabel);
  await captureForm.locator('input[name="device_name"]').fill('CI Browser');
  await submitAndWait(page, captureForm, (url) => url.pathname === `/spatial/edit/${sceneId}` && url.searchParams.has('status_message'));
  assert((await page.locator('body').innerText()).includes(captureLabel), 'Spatial capture did not persist.');

  const hotspotForm = page.locator(`form[action="/spatial/hotspot/${sceneId}"]`);
  const hotspotTitle = `Acceptance hotspot ${suffix}`;
  await hotspotForm.locator('input[name="title"]').fill(hotspotTitle);
  await hotspotForm.locator('textarea[name="body"]').fill('Functional hotspot.');
  await hotspotForm.locator('input[name="position_x"]').fill('1');
  await hotspotForm.locator('input[name="position_y"]').fill('2');
  await hotspotForm.locator('input[name="position_z"]').fill('3');
  await submitAndWait(page, hotspotForm, (url) => url.pathname === `/spatial/edit/${sceneId}` && url.searchParams.has('status_message'));
  assert((await page.locator('body').innerText()).includes(hotspotTitle), 'Spatial hotspot did not persist.');

  await goto200(page, '/spatial/manage?q=' + encodeURIComponent(updatedSceneTitle), '[data-spatial-manage]');
  assert((await page.locator('body').innerText()).includes(updatedSceneTitle), 'Spatial search did not return updated scene.');

  await goto200(page, `/spatial/edit/${sceneId}`, 'form[action^="/spatial/save"]');
  const publishForm = page.locator(`form[action="/spatial/publish/${sceneId}"]`);
  if (await publishForm.count()) {
    await submitAndWait(page, publishForm, (url) => url.pathname === `/spatial/edit/${sceneId}` && url.searchParams.has('status_message'));
    assert((await page.locator('body').innerText()).includes('Немає готового основного asset'), 'Spatial publish failure state is not readable.');
  }

  if (pageErrors.length) {
    throw new Error('Functional acceptance browser errors:\n- ' + [...new Set(pageErrors)].join('\n- '));
  }

  console.log(JSON.stringify({
    ok: true,
    suite: 'Production Cutover functional acceptance',
    covered: [
      'admin users create/filter/update/login/duplicate validation',
      'client case create/edit/activity/search/quick-update/dynamic deal route',
      'sales lead validation/create/qualify/convert/deal/pipeline/message',
      'property public submission/moderation/dynamic detail',
      'spatial validation/create/edit/capture/hotspot/search/publish failure',
    ],
    dynamic: { caseId, leadId, dealId, sceneId, submissionHref },
  }, null, 2));
} finally {
  await adminContext.close();
  await browser.close();
}
