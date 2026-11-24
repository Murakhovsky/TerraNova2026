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
const context = await browser.newContext({viewport:{width:1440,height:1000}});
const page = await context.newPage();
const go = async path => {
  const r = await page.goto(url(path),{waitUntil:'domcontentloaded'});
  check(r && r.status()<400 && !new URL(page.url()).pathname.startsWith('/auth/login'), 'Navigation failed: '+path+' '+r?.status());
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
  const products=[];
  for (const suffix of ['A','B']) {
    await go('/product-management');
    const form=page.locator('form[action="/product-management/products"]');
    await form.locator('[name=name]').fill('PM Browser '+tag+suffix);
    await form.locator('[name=sku]').fill('PM'+tag+suffix);
    await submit(form,'/product-management/products');
    await page.waitForURL(u=>/^\/product-management\/products\/[a-f0-9]{32}$/.test(u.pathname));
    const productId=new URL(page.url()).pathname.split('/').pop();
    const variant=await page.locator('#list-price select[name=list_variant] option').first().getAttribute('value');
    check(/^[a-f0-9]{32}$/.test(variant||''),'Product variant missing');
    products.push({productId,variant,sku:'PM'+tag+suffix});
  }
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
  await matrix.locator('button[type=submit]').click();
  const apply=page.locator('form[action="'+attrPath+'"]').filter({has:page.locator('[name=action][value=matrix]')});
  await apply.locator('[name=confirm_matrix]').check();
  await submit(apply,attrPath);
  await page.waitForURL(u=>u.searchParams.get('notice')==='updated');
  check((await page.locator('#variant-matrix').innerText()).includes('QAM'+tag),'Matrix created SKU not visible');
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
  await release.locator('[name=reason]').fill('QA release');
  await release.locator('[name=confirm_release]').check();
  await submit(release,'/product-management/stock/reservation');
  await page.waitForURL(u=>u.searchParams.get('notice')==='posted');
  check(await page.getByText('QA release',{exact:true}).count()===1,'Release not visible');

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
  await page.setViewportSize({width:390,height:844});
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
