import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';

// Opt-in fixture only: pre-created ambiguous/expired Workflow with an independently
// verifiable native receipt. No live provider or production data may be used.
const required = ['BASE_URL','MANAGER_EMAIL','MANAGER_PASSWORD','SECOND_MANAGER_EMAIL','SECOND_MANAGER_PASSWORD',
    'RECOVERY_RUN','RECOVERY_STEP','RECOVERY_RECEIPT'];
for(const key of required) assert.ok(process.env['UX05_E2E_'+key], 'Missing UX05_E2E_'+key);
assert.equal(process.env.UX05_E2E_STAGING,'1');
assert.equal(process.env.UX05_E2E_MUTATION_SAFE,'1','A disposable, mutation-safe recovery fixture is required.');
const env = key => process.env['UX05_E2E_'+key];
const url = path => new URL(path,env('BASE_URL')).href;
const run=env('RECOVERY_RUN'), step=env('RECOVERY_STEP'), receipt=env('RECOVERY_RECEIPT');
const runPath='/workspace/operations?operation='+encodeURIComponent('workflow:'+run);
const submitPath='/workspace/operations/workflow/'+encodeURIComponent(run)+'/recovery';
const historyPath='/api/v1/workflows/'+encodeURIComponent(run)+'/recovery/requests';
const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
const login=async prefix=>{
    const context=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block'});
    const page=await context.newPage();
    await page.goto(url('/auth/login'));
    await page.locator('input[name=email]').fill(env(prefix+'_EMAIL'));
    await page.locator('input[name=password]').fill(env(prefix+'_PASSWORD'));
    await Promise.all([page.waitForURL(u=>!u.pathname.startsWith('/auth/login')),page.locator('button[type=submit]').click()]);
    return {context,page};
};
const go=async page=>{
    const response=await page.goto(url(runPath));
    assert.equal(response.status(),200,'Workflow detail unavailable');
    assert.equal(await page.locator('[data-ux05-detail]').getAttribute('data-ux05-detail'),'workflow:'+run);
};
const forms=(page,operation)=>page.locator('form[data-ux05-recovery-operation="'+operation+'"][data-ux05-recovery-resolution="verified_complete"]');
const select=async(page,operation,resource)=>{
    const list=forms(page,operation);
    const matches=[];
    for(let i=0;i<await list.count();i++){
        const candidate=list.nth(i);
        if(await candidate.getAttribute('data-ux05-recovery-step')===step &&
            (resource===undefined || await candidate.getAttribute('data-ux05-recovery-resource')===resource)) matches.push(candidate);
    }
    assert.equal(matches.length,1,'Expected exactly one '+operation+' descriptor for the fixture');
    return matches[0];
};
const formData=async form=>Object.fromEntries(await form.evaluate(f=>Array.from(new FormData(f).entries())));
const post=async(context,data)=>context.request.post(url(submitPath),{form:data,maxRedirects:0});
const history=async context=>{
    const response=await context.request.get(url(historyPath));
    assert.equal(response.status(),200,'Canonical recovery history unavailable');
    const json=await response.json();
    assert.equal(json.ok,true);
    return json.data;
};
try {
    const first=await login('MANAGER'), second=await login('SECOND_MANAGER');
    await go(first.page);
    const before=await history(first.context);
    const requestForm=await select(first.page,'request');
    await requestForm.locator('[name=receipt_reference]').fill(receipt);
    await requestForm.locator('[name=reason]').fill('Staging acceptance: independently verified native receipt.');
    await requestForm.locator('[name=confirmed]').check();
    const requestData=await formData(requestForm);
    assert.equal((await post(first.context,{...requestData,csrf_token:'invalid'})).status(),403,'Bad CSRF permitted');
    assert.equal((await post(first.context,{...requestData,confirmed:'0'})).status(),422,'Missing confirmation permitted');
    assert.equal((await post(first.context,requestData)).status(),303,'Canonical recovery request failed');
    const result=await history(first.context);
    const created=result.filter(r=>!before.some(b=>b.request_id===r.request_id));
    assert.equal(created.length,1,'Expected a single new canonical request');
    const requestId=created[0].request_id;
    assert.equal(created[0].status,'REQUESTED');
    assert.equal((await post(first.context,requestData)).status(),409,'Repeated petition not fenced');
    assert.equal((await history(first.context)).filter(r=>r.request_id===requestId).length,1,'Duplicate petition');
    await go(first.page);
    const selfApprove=forms(first.page,'approve');
    for(let i=0;i<await selfApprove.count();i++) assert.notEqual(await selfApprove.nth(i).getAttribute('data-ux05-recovery-resource'),requestId,'Self-approval control exposed');
    await go(second.page);
    const approve=await select(second.page,'approve',requestId);
    await approve.locator('[name=confirmed]').check();
    const approvalData=await formData(approve);
    // Exact descriptor from manager #2 may not be submitted by the requester.
    assert.equal((await post(first.context,{...approvalData,csrf_token:requestData.csrf_token})).status(),409,'Self-approval POST permitted');
    assert.equal((await post(second.context,approvalData)).status(),303,'Independent approval failed');
    const approved=(await history(second.context)).find(r=>r.request_id===requestId);
    assert.equal(approved.status,'APPROVED');
    assert.notEqual(approved.approved_by,approved.requested_by);
    assert.equal((await post(second.context,approvalData)).status(),409,'Repeated approval not fenced');
    await go(second.page);
    const apply=await select(second.page,'apply',requestId);
    await apply.locator('[name=confirmed]').check();
    const applyData=await formData(apply);
    assert.equal((await post(second.context,applyData)).status(),303,'Canonical apply failed');
    assert.equal((await history(second.context)).find(r=>r.request_id===requestId).status,'APPLIED');
    assert.equal((await post(second.context,applyData)).status(),409,'Repeated apply not fenced');
    assert.equal((await history(second.context)).filter(r=>r.request_id===requestId).length,1);
    console.log('UX-05 recovery: CSRF, confirmation, stable petition, independent approval, canonical apply and duplicate POST fences passed.');
    await first.context.close(); await second.context.close();
}finally{await browser.close();}
