import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { mkdir } from 'node:fs/promises';

// Staging-only read acceptance. Requires two tenant fixtures and a non-manager.
// No approval, retry or provider mutation is performed.
const required = [
    'UX05_E2E_BASE_URL', 'UX05_E2E_MANAGER_EMAIL', 'UX05_E2E_MANAGER_PASSWORD',
    'UX05_E2E_MEMBER_EMAIL', 'UX05_E2E_MEMBER_PASSWORD', 'UX05_E2E_OWN_OPERATION',
    'UX05_E2E_FOREIGN_OPERATION', 'UX05_E2E_APPROVAL_ID',
];
for (const name of required) assert.ok(process.env[name], 'Missing fixture: '+name);
assert.equal(process.env.UX05_E2E_STAGING, '1', 'Only staging/disposable fixtures are permitted.');
const url = path => new URL(path, process.env.UX05_E2E_BASE_URL).href;
const output = process.env.UX05_E2E_OUTPUT_DIR || 'tmp/cuw-ux05-browser';
await mkdir(output, {recursive:true});
const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
const openContext = async (role, viewport) => {
    const context = await browser.newContext({viewport,serviceWorkers:'block'});
    const page = await context.newPage();
    await page.goto(url('/auth/login'));
    await page.locator('input[name=email]').fill(process.env['UX05_E2E_'+role+'_EMAIL']);
    await page.locator('input[name=password]').fill(process.env['UX05_E2E_'+role+'_PASSWORD']);
    await Promise.all([page.waitForURL(u=>!u.pathname.startsWith('/auth/login')), page.locator('button[type=submit]').click()]);
    return {context,page};
};
const operation = key => '/workspace/operations?operation='+encodeURIComponent(key);
try {
    const {context,page} = await openContext('MANAGER',{width:390,height:844});
    const own = process.env.UX05_E2E_OWN_OPERATION, foreign = process.env.UX05_E2E_FOREIGN_OPERATION;
    const states = [];
    for (const mode of ['result','process','expert']) {
        const result = await page.goto(url(operation(own)+'&mode='+mode));
        assert.equal(result.status(),200);
        assert.equal(await page.locator('[data-ux05-detail]').getAttribute('data-ux05-detail'),own);
        states.push(await page.locator('[data-ux05-detail] [data-operation-status]').getAttribute('data-operation-status'));
        const width = await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}));
        assert.ok(width.scroll<=width.viewport+1,'Mobile page overflow in '+mode);
        await page.screenshot({path:output+'/'+mode+'-390.png',fullPage:true});
    }
    assert.equal(new Set(states).size,1,'Modes cannot change canonical operation status');
    await context.setOffline(true);
    await page.waitForFunction(()=>document.querySelector('[data-operations-snapshot-target=state]')?.dataset.connection==='offline');
    await context.setOffline(false);
    await page.waitForFunction(()=>['stale','reconnecting','live'].includes(document.querySelector('[data-operations-snapshot-target=state]')?.dataset.connection));
    const foreignResponse = await page.goto(url(operation(foreign)));
    assert.equal(foreignResponse.status(),200);
    assert.equal(await page.locator('[data-ux05-detail]').count(),0,'Foreign detail disclosed');
    const csrf = await context.request.post(url('/workspace/operations/approval/'+encodeURIComponent(process.env.UX05_E2E_APPROVAL_ID)+'/decide'), {
        form:{csrf_token:'deliberately-invalid',ui_action:'invalid'},maxRedirects:0,
    });
    assert.equal(csrf.status(),403,'CSRF negative must be denied');
    await context.close();
    const member = await openContext('MEMBER',{width:1280,height:900});
    const forbidden = await member.page.goto(url(operation(own)));
    assert.equal(forbidden.status(),403,'Member must not access manager Operations');
    await member.context.close();
    console.log('UX-05 browser: mode parity, 390px, offline/reconnect, foreign detail, CSRF and member denial passed.');
} finally { await browser.close(); }
