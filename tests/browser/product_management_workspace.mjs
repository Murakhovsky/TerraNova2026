import { chromium } from 'playwright-core';
import { mkdir } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';

const base = process.env.PM_E2E_BASE_URL;
const email = process.env.PM_E2E_EMAIL;
const password = process.env.PM_E2E_PASSWORD;
if (!base || !email || !password || process.env.PM_E2E_MUTATION_SAFE !== '1') {
  throw new Error('Product browser acceptance requires a disposable authenticated fixture.');
}
const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
const tag = randomBytes(5).toString('hex').toUpperCase();
const dir = process.env.PM_E2E_OUTPUT_DIR || 'tmp/product-management-browser';
await mkdir(dir,{recursive:true});
const url = path => new URL(path,base).href;
const check = (ok,msg) => {if(!ok)throw new Error(msg);};
const context = await browser.newContext({viewport:{width:1440,height:1000},serviceWorkers:'block'});
const page = await context.newPage();
const go = async path => {
  const r = await page.goto(url(path),{waitUntil:'domcontentloaded'});
  const actual=new URL(page.url()).pathname;
  check(r && r.status()<400 && actual===new URL(url(path)).pathname, 'Navigation failed: '+path+' status='+r?.status()+' final='+actual+' title='+await page.title());
  check(await page.locator('.cos-page__stack').count()>0,'Workspace shell missing: '+actual+' title='+await page.title()+' body='+(await page.locator('body').innerText()).slice(0,600));
};
const submit = async (form, path) => {
  const [r] = await Promise.all([
    page.waitForResponse(r => new URL(r.url()).pathname===path && r.request().method()==='POST'),
    form.locator('button[type="submit"], button:not([type])').last().click()
  ]);
  check(r.status()===303,'Expected 303 for '+path+', got '+r.status()+' '+(await r.text().catch(()=>'' )).slice(0,500));
  await page.waitForLoadState('domcontentloaded');
};
try {
  await page.goto(url('/auth/login'));
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await Promise.all([page.waitForURL(u=>!u.pathname.startsWith('/auth/login')),page.locator('button[type=submit]').click()]);
  await go('/product-management');
  const inventoryResponse=await context.request.get(url('/api/v1/capabilities'));
  check(inventoryResponse.ok(),'Authenticated Capability Library unavailable: '+inventoryResponse.status());
  const inventory=(await inventoryResponse.json()).capabilities.filter(c=>c.owner_domain==='product_management');
  check(inventory.length===95,'Product Capability Library must expose 95 typed capabilities');
  const counts={};
  for(const c of inventory){
    counts[c.status]=(counts[c.status]||0)+1;
    check(c.contract&&c.status!=='unavailable','Unavailable Product binding: '+c.id+' '+c.reason);
  }
  check(counts.read_only===31&&counts.direct_human===30&&counts.workflow_approval_required===34,'Unexpected Product execution modes: '+JSON.stringify(counts));
  const csrf=await page.locator('form[action="/product-management/products"] [name=csrf_token]').getAttribute('value');
  const headers={'X-CSRF-Token':csrf,'X-Idempotency-Key':'pm-browser-'+tag};
  const summary=await context.request.post(url('/api/v1/capabilities/product_management.analytics.summary.get/invoke'),{headers,data:{currency:'UAH'}});
  check(summary.ok()&&(await summary.json()).data.currency==='UAH','Canonical read API unavailable');
  const denied=await context.request.post(url('/api/v1/capabilities/product_management.governed.product.create/invoke'),{headers,data:{target_type:'product_governed_operation',target_id:'a'.repeat(32),parameters:{name:'Denied',sku:'DENIED'+tag}}});
  check(denied.status()===422,'Governed Action direct invocation was not denied');
  const anonymous=await browser.newContext({serviceWorkers:'block'});
  try{
    const rejected=await anonymous.request.post(url('/api/v1/capability-intents/product-business'),{data:{kind:'product_management.governed_plan.v1',description:'Unsigned',operations:[]}});
    check(rejected.status()===403,'Unsigned machine endpoint not denied');
    const inaccessible=await anonymous.request.get(url('/api/v1/capabilities'));
    check(inaccessible.status()===403||inaccessible.status()===401,'Anonymous capability inventory not denied');
  }finally{await anonymous.close();}
  const products=[];
  for (const suffix of ['A','B']) {
    await go('/product-management');
    const form=page.locator('form[action="/product-management/products"]');
    check(await form.count()===1,'Expected catalog create form missing: '+page.url()+' title='+await page.title()+' body='+(await page.locator('body').innerText()).slice(0,800));
    await form.locator('[name=name]').fill('PM Browser '+tag+suffix);
    await form.locator('[name=sku]').fill('PM'+tag+suffix);
    await submit(form,'/product-management/products');
    await page.waitForURL(u=>/^\/product-management\/products\/[a-f0-9]{32}$/.test(u.pathname));
    const productId=new URL(page.url()).pathname.split('/').pop();
    const variant=await page.locator('form[action="/product-management/products/'+productId+'/price"] input[name=variant_id]').first().getAttribute('value');
    check(/^[a-f0-9]{32}$/.test(variant||''),'Product variant missing');
    products.push({productId,variant,sku:'PM'+tag+suffix});
  }
  const mediaProduct=products[0];
  const mediaPath='/product-management/products/'+mediaProduct.productId;
  await go(mediaPath);
  const upload=page.locator('form[action="'+mediaPath+'/media/upload"]');
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==','base64');
  await upload.locator('[name=image]').setInputFiles({name:'QA-'+tag+'.png',mimeType:'image/png',buffer:png});
  await submit(upload,mediaPath+'/media/upload');
  await page.waitForURL(u=>u.searchParams.get('notice')==='image_uploaded');
  const imageId=new URL(page.url()).searchParams.get('media_file');
  check(!!imageId,'Uploaded Documents file not selected');
  const preview=await context.request.get(url('/product-management/media/images/'+imageId));
  check(preview.ok()&&preview.headers()['content-type']==='image/png'&&(await preview.body()).equals(png),'Verified image preview mismatch');
  const attach=page.locator('form[action="'+mediaPath+'/media"]');
  await attach.locator('[name=role]').selectOption('cover');
  await submit(attach,mediaPath+'/media');
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const detach=page.locator('form[action^="'+mediaPath+'/media/"][action$="/detach"]');
  check(await detach.count()===1,'Attached image link missing');
  await page.screenshot({path:dir+'/media-desktop.png',fullPage:true});
  await submit(detach,new URL(await detach.getAttribute('action'),base).pathname);
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  check(await detach.count()===0,'Detached image still linked');
  check((await context.request.get(url('/product-management/media/images/'+imageId))).ok(),'Detach deleted Documents image');
  await go('/product-management/categories');
  const cat=page.locator('form[action="/product-management/categories"]');
  const slug='qa-'+tag.toLowerCase();
  await cat.locator('[name=name]').fill('QA Category '+tag);
  await cat.locator('[name=slug]').fill(slug);
  await submit(cat,'/product-management/categories');
  await page.waitForURL(u=>u.searchParams.get('notice')==='created');
  await go('/product-management/attributes');
  const definition=page.locator('form[action="/product-management/attributes"]').filter({has:page.locator('[name=action][value=define]')});
  const attrCode='finish_'+tag.toLowerCase();
  await definition.locator('[name=code]').fill(attrCode);
  await definition.locator('[name=name]').fill('Finish '+tag);
  await definition.locator('[name=value_type]').selectOption('enum');
  await definition.locator('[name=choices]').fill('oak,walnut');
  await submit(definition,'/product-management/attributes');
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const catSelect=page.locator('select[name=category]');
  const catId=await catSelect.locator('option').filter({hasText:'QA Category '+tag}).getAttribute('value');
  check(/^[a-f0-9]{32}$/.test(catId||''),'Attribute category missing');
  await go('/product-management/attributes?category='+catId);
  const binding=page.locator('form[action="/product-management/attributes"]').filter({has:page.locator('[name=action][value=bind]')});
  await binding.locator('[name=code]').fill(attrCode);
  await binding.locator('[name=is_axis]').check();
  await submit(binding,'/product-management/attributes');
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const attrProduct=products[0];
  const productPath='/product-management/products/'+attrProduct.productId;
  await go(productPath);
  const assign=page.locator('form').filter({has:page.locator('select[name=category_id]')});
  await assign.locator('select[name=category_id]').selectOption(catId);
  const assignPath=new URL(await assign.getAttribute('action'),base).pathname;
  await submit(assign,assignPath);
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const attrPath=productPath+'/attributes';
  await go(attrPath);
  const patch=page.locator('form[action="'+attrPath+'"]').filter({has:page.locator('[name=action][value=set]')});
  await patch.locator('[name=values]').fill(JSON.stringify([{code:attrCode,value:'oak'}]));
  await submit(patch,attrPath);
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const matrix=page.locator('form[action="'+attrPath+'"]').filter({has:page.locator('[name=action][value=preview]')});
  await matrix.locator('[name=sku_prefix]').fill('QAM'+tag);
  await matrix.locator('[name=axes]').fill(JSON.stringify([{code:attrCode,choices:['oak','walnut']}]));
  const originalVariants=await page.locator('#matrix-existing-variants tbody tr').allTextContents();
  const [previewResponse]=await Promise.all([
    page.waitForResponse(r=>new URL(r.url()).pathname===attrPath && r.request().method()==='POST'),
    matrix.locator('button[type=submit]').click()
  ]);
  check(previewResponse.status()===200,'Matrix preview expected HTTP 200, got '+previewResponse.status()+' '+(await previewResponse.text().catch(()=>'' )).slice(0,500));
  const previewRows=page.locator('#matrix-preview tbody tr');
  await previewRows.first().waitFor({state:'visible'});
  check(await previewRows.count()===2,'Expected two matrix preview combinations');
  check((await previewRows.allTextContents()).every(row=>row.includes('ready')&&row.includes('QAM'+tag)),'Preview SKU/status mismatch');
  check(JSON.stringify(await page.locator('#matrix-existing-variants tbody tr').allTextContents())===JSON.stringify(originalVariants),'Preview mutated native variants');
  const apply=page.locator('form[action="'+attrPath+'"]').filter({has:page.locator('[name=action][value=matrix]')});
  await apply.locator('[name=confirm_matrix]').check();
  await submit(apply,attrPath);
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  const createdRows=await page.locator('#matrix-existing-variants tbody tr').allTextContents();
  check(createdRows.filter(row=>row.includes('QAM'+tag)).length===2,'Expected two persisted matrix SKUs');
  await page.screenshot({path:dir+'/attributes-desktop.png',fullPage:true});
  await go('/product-management/attributes?filter_code='+attrCode+'&filter_value='+encodeURIComponent('"oak"'));
  check(await page.locator('input[name="selected[]"][value="'+attrProduct.productId+'"]').count()===1,'Typed filter result missing');
  await go('/product-management/stock');
  const locForm=page.locator('form[action="/product-management/stock/locations"]');
  await locForm.locator('[name=code]').fill('WH'+tag);
  await locForm.locator('[name=name]').fill('PM QA warehouse '+tag);
  await submit(locForm,'/product-management/stock/locations');
  await page.waitForURL(u=>u.searchParams.get('notice')==='created');
  const location=await page.locator('#stock-session select[name=location] option').filter({hasText:'WH'+tag}).getAttribute('value');
  check(/^[a-f0-9]{32}$/.test(location||''),'Warehouse missing');
  for(const p of products){
    await go('/product-management/stock?variant='+p.variant+'&location='+location);
    const form=page.locator('form[action="/product-management/stock/post"]');
    await form.locator('[name=kind]').selectOption('receive');
    await form.locator('[name=variant_id]').selectOption(p.variant);
    await form.locator('[name=from_location_id]').selectOption(location);
    await form.locator('[name=quantity]').fill('5');
    await form.locator('[name=source_ref]').fill('qa:'+tag+':'+p.sku);
    await submit(form,'/product-management/stock/post');
    await page.waitForURL(u=>u.searchParams.get('notice')==='posted');
  }
  const p=products[0];
  await go('/product-management/stock?variant='+p.variant+'&location='+location);
  const reserve=page.locator('form[action="/product-management/stock/reservation"]').filter({has:page.locator('[name=action][value=reserve]')});
  await reserve.locator('[name=quantity]').fill('1');
  await reserve.locator('[name=source_ref]').fill('manual:'+tag);
  await submit(reserve,'/product-management/stock/reservation');
  await page.waitForURL(u=>u.searchParams.get('notice')==='posted');
  const release=page.locator('form[action="/product-management/stock/reservation"]').filter({has:page.locator('[name=action][value=release]')});
  const releasedId=await release.locator('[name=reservation_id]').getAttribute('value');
  check(/^[a-f0-9]{32}$/.test(releasedId||''),'Reservation identity missing');
  await release.locator('[name=reason]').fill('QA release');
  await release.locator('[name=confirm_release]').check();
  await submit(release,'/product-management/stock/reservation');
  await page.waitForURL(u=>u.searchParams.get('notice')==='posted');
  const released=page.locator('[data-reservation-id="'+releasedId+'"]');
  await released.getByText('QA release',{exact:true}).waitFor({state:'visible'});
  check((await released.locator('td').nth(2).innerText()).trim()==='released','Reservation not released');

  await go('/product-management/price-lists');
  const list=page.locator('form[action="/product-management/price-lists"]');
  const code='QA'+tag;
  await list.locator('[name=code]').fill(code);
  await list.locator('[name=name]').fill('QA Wholesale '+tag);
  await list.locator('[name=kind]').selectOption('wholesale');
  await submit(list,'/product-management/price-lists');
  await page.waitForURL(u=>u.searchParams.get('notice')==='created');
  const path='/product-management/products/'+p.productId;
  const query='?list_code='+code+'&list_variant='+p.variant;
  await go(path+query);
  const tier=page.locator('form[action="'+path+'/list-price"]').filter({has:page.locator('[name=price_action][value=tier_set]')}).filter({has:page.locator('[name=expected_revision][value="0"]')});
  await tier.locator('[name=min_quantity]').fill('10');
  await tier.locator('[name=amount]').fill('12.5');
  await submit(tier,path+'/list-price');
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  await go(path+query+'&quote_qty=10&tier_history_min=10#tier-history');
  check((await page.locator('#tier-history').innerText()).includes('12.5000'),'Tier history missing');
  check((await page.locator('#list-price').innerText()).includes('(tier)'),'Quantity quote did not resolve');
  await page.screenshot({path:dir+'/pricing-desktop.png',fullPage:true});

  await go('/product-management/stock?location='+location);
  const session=page.locator('form[action="/product-management/stock/count/session/propose"]');
  await session.locator('[name=source_ref]').fill('count:'+tag);
  for(const [i,item] of products.entries()){
    const row=session.locator('[data-count-variant="'+item.variant+'"]');
    check((await row.innerText()).includes('5.000000'),'Before count balance missing');
    await row.locator('input[inputmode=decimal]').fill(i===0?'3':'5');
  }
  await submit(session,'/product-management/stock/count/session/propose');
  await page.waitForURL(u=>u.searchParams.get('notice')==='session_proposed');
  check(/^[a-f0-9]{32}$/.test(new URL(page.url()).searchParams.get('session_id')||''),'Session identity missing');
  check((await page.locator('#stock-session').innerText()).includes('Залишки не змінено'),'Proposal acknowledgement missing');
  check((await page.locator('[data-count-variant="'+p.variant+'"]').innerText()).includes('5.000000'),'Proposal mutated stock');
  await page.screenshot({path:dir+'/session-desktop.png',fullPage:true});
  await go('/product-management/dashboard?tab=stock&risk=all&location='+location);
  check(await page.locator('#pm-dashboard').isVisible(),'Dashboard KPIs missing');
  check((await page.locator('#pm-report').innerText()).includes(p.sku),'Dashboard stock drilldown missing seeded SKU');
  await page.screenshot({path:dir+'/dashboard-desktop.png',fullPage:true});
  await go('/product-management/dashboard?tab=movement');
  check((await page.locator('#pm-report').innerText()).includes('Фізична оборотність'),'Movement basis missing');
  await go('/product-management/dashboard?tab=quality&kind=no_media');
  check(await page.locator('#pm-report table').isVisible(),'Catalog quality report missing');
  await go('/product-management/dashboard?tab=audit');
  check((await page.locator('#pm-report').innerText()).includes('Перевірено позицій'),'Ledger reconciliation report missing');
  await page.setViewportSize({width:390,height:844});
  await go('/product-management/dashboard?tab=stock&risk=all&location='+location);
  check(await page.locator('#pm-dashboard').isVisible(),'Mobile dashboard missing');
  await page.screenshot({path:dir+'/dashboard-mobile.png',fullPage:true});
  await go('/product-management/stock?location='+location);
  check(await page.locator('#stock-session').isVisible(),'Mobile count session missing');
  await page.screenshot({path:dir+'/session-mobile.png',fullPage:true});
  await go(path+query+'&tier_history_min=10');
  check(await page.locator('#tier-history').isVisible(),'Mobile price history missing');
  await page.screenshot({path:dir+'/pricing-mobile.png',fullPage:true});
  console.log('Product Management authenticated browser journey PASS (approval proposal only; worker admission separately tested).');
} catch(e) {
  await page.screenshot({path:dir+'/failure.png',fullPage:true}).catch(()=>{});
  throw e;
} finally {
  await browser.close();
}
