import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const load = file => JSON.parse(fs.readFileSync(path.join(root,file),'utf8'));
function compare(label, actual, expected) {
  const errors=[];
  for (const [key,value] of Object.entries(actual)) if (!Object.hasOwn(expected,key)||expected[key]!==value) errors.push(label+': new or changed exception '+key);
  for (const key of Object.keys(expected)) if (!Object.hasOwn(actual,key)) errors.push(label+': stale exception '+key);
  return errors;
}
// A PR may retire exceptions, but may not introduce or substitute them.
function additions(current, previous, label) {
  const errors=[];
  for (const [key,value] of Object.entries(current)) if (!Object.hasOwn(previous,key)||previous[key]!==value) errors.push(label+': new exception relative to base '+key);
  return errors;
}
function flatten(registry) {
  return Object.fromEntries(Object.entries(registry.domains??{}).flatMap(([domain,ids])=>ids.map(id=>[id,domain])));
}
function gapMap(debt) {
  return Object.fromEntries((debt.items??[]).map(item=>[item.id,JSON.stringify([item.owner_domain,item.target_capability,item.gap_type])]));
}
function inspect(modules, processes, registry, baseline, debt) {
  const issues=[], legacy={}, owners={}, expected={}, refs={};
  const typed=new Set();
  if (registry.schema_version!==1||registry.standard!=='DAS-001') issues.push('Invalid legacy inventory schema');
  for (const [domain,ids] of Object.entries(registry.domains??{})) {
    if (!Array.isArray(ids)||!ids.length||new Set(ids).size!==ids.length) {issues.push(domain+': invalid identity list');continue;}
    for (const id of ids) {
      if (typeof id!=='string'||!id.startsWith(domain+'.')) issues.push(domain+': invalid identity '+id);
      expected[id]=domain;
    }
  }
  for (const module of modules) {
    const domain=module.id, c=module.contributions??{};
    const contracts=new Set((c.capability_contracts??[]).map(x=>x.id));
    for (const id of contracts) typed.add(id);
    let count=0;
    for (const id of c.capabilities??[]) {
      owners[id]=domain;
      if (!contracts.has(id)) {legacy[id]=domain;count++;}
    }
    if (baseline.domains?.[domain]?.max_descriptive_only!==count) issues.push(domain+': descriptive ceiling must equal remaining debt '+count);
  }
  issues.push(...compare('Legacy surface',legacy,expected));
  for (const p of processes) for (const s of p.steps??[]) {
    if (typeof s.capability!=='string'||!s.capability) continue;
    const key=p.id+':'+s.id;
    if (owners[s.capability]!==s.domain) issues.push(key+': invalid capability ownership');
    else if (!typed.has(s.capability)) refs[key]=s.capability;
  }
  issues.push(...compare('Descriptive process reference',refs,registry.process_references??{}));
  return {issues,legacy:Object.keys(legacy).length,descriptiveProcessSteps:Object.keys(refs).length,explicitProcessGaps:(debt.items??[]).length,typed:typed.size};
}
if (process.argv.includes('--self-test')) {
  const m={id:'demo',contributions:{capabilities:['demo.old'],capability_contracts:[]}};
  const p={id:'demo.flow',steps:[{id:'one',domain:'demo',capability:'demo.old'}]};
  const reg={schema_version:1,standard:'DAS-001',domains:{demo:['demo.old']},process_references:{'demo.flow:one':'demo.old'}};
  const baseline={domains:{demo:{max_descriptive_only:1}}};
  const check=(ms,ps,rs=reg,bs=baseline)=>inspect(ms,ps,rs,bs,{items:[]}).issues;
  if(check([m],[p]).length) throw Error('Positive fixture failed');
  if(!check([{...m,contributions:{capabilities:['demo.new']}}],[]).some(e=>e.includes('new or changed exception demo.new'))) throw Error('Same-count substitution escaped');
  if(!check([m],[{...p,steps:[...p.steps,{...p.steps[0],id:'two'}]}]).some(e=>e.includes('demo.flow:two'))) throw Error('New descriptive process mapping escaped');
  const normalized={...m,contributions:{...m.contributions,capability_contracts:[{id:'demo.old'}]}};
  if(!check([normalized],[p]).some(e=>e.includes('stale exception'))) throw Error('Stale exception escaped');
  if(check([normalized],[p],{...reg,domains:{},process_references:{}},{domains:{demo:{max_descriptive_only:0}}}).length) throw Error('Valid retirement rejected');
  if(additions({'demo.new':'demo'},{'demo.old':'demo'},'legacy').length!==1) throw Error('Base comparison substitution escaped');
  if(additions({}, {'demo.old':'demo'},'legacy').length) throw Error('Base comparison retirement rejected');
  if(additions({'flow:step':'demo.new'},{'flow:step':'demo.old'},'process').length!==1) throw Error('Base process substitution escaped');
  console.log('DAS-001 exact legacy identity gate: positive and seven regression fixtures PASS');
} else {
  const modules=fs.readdirSync(path.join(root,'app/Domains'),{withFileTypes:true})
    .filter(d=>d.isDirectory()&&fs.existsSync(path.join(root,'app/Domains',d.name,'module.php')))
    .map(d=>JSON.parse(execFileSync('php',['-r','echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);',path.join(root,'app/Domains',d.name,'module.php')],{encoding:'utf8',timeout:10000})));
  const processes=fs.readdirSync(path.join(root,'resources/processes')).filter(f=>f.endsWith('.json')).map(f=>load('resources/processes/'+f));
  const inventoryPath='docs/.vitepress/das-001-legacy-surface.json';
  const debtPath='docs/.vitepress/capability-debt.json';
  const inventory=load(inventoryPath), debt=load(debtPath);
  const result=inspect(modules,processes,inventory,load('docs/.vitepress/das-001-compliance-baseline.json'),debt);
  const base=process.env.DAS001_BASE_SHA;
  if (base) {
    if (!/^[a-f0-9]{40}$/.test(base)) throw Error('Expected exact DAS001_BASE_SHA');
    const git=(args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',timeout:10000,stdio:['ignore','pipe','pipe']});
    git(['cat-file','-e',base+'^{commit}']);
    const hasInventory=git(['ls-tree','--name-only',base,'--',inventoryPath]).trim()!=='';
    if (hasInventory) {
      const previous=JSON.parse(git(['show',base+':'+inventoryPath]));
      result.issues.push(...additions(flatten(inventory),flatten(previous),'Legacy surface'));
      result.issues.push(...additions(inventory.process_references??{},previous.process_references??{},'Descriptive process reference'));
    } else {
      console.log('Initial inventory adoption: base commit has no inventory; exact source audit still applies.');
    }
    const previousDebt=JSON.parse(git(['show',base+':'+debtPath]));
    result.issues.push(...additions(gapMap(debt),gapMap(previousDebt),'Explicit process gap'));
  }
  const clean=result.issues.length===0&&result.legacy===0&&result.descriptiveProcessSteps===0&&result.explicitProcessGaps===0;
  console.log(JSON.stringify({...result,zeroDebt:clean},null,2));
  if(result.issues.length||(process.argv.includes('--zero-debt')&&!clean)) process.exitCode=1;
}
