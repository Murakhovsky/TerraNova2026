import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { buildDomainProcessCoverage } from './domain-process-coverage.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const domainsRoot = path.join(root, 'app', 'Domains');
const docsRoot = path.join(root, 'docs', '04-domains');
const errors = [];
const required = [
  'Ідентичність', 'Межі відповідальності', 'Внутрішні компоненти',
  'Бізнесова модель', 'Use Cases та Capabilities', 'Виконання',
  'Міждоменні контракти', 'Процеси та результати', 'Tenant, Policy та Security',
  'Робочий простір Unified Workspace', 'Operations та Recovery', 'Готовність і докази',
  'План розвитку, борги та рішення', 'Карта джерел'
];
const asArray = (v) => Array.isArray(v) ? v : [];
const error = (msg) => errors.push(msg);

function manifestAt(file) {
  // The documentation pipeline already requires PHP for its reference generators.
  return JSON.parse(execFileSync('php', ['-r',
    'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', file
  ], { encoding: 'utf8', timeout: 10000 }));
}
function machineBlock(text, id) {
  const m = text.match(/<!-- COS-DOMAIN-PASSPORT-DATA\s*([\s\S]*?)\s*COS-DOMAIN-PASSPORT-DATA -->/);
  if (!m) throw new Error(id + ': missing COS-DOMAIN-PASSPORT-DATA block');
  return JSON.parse(m[1]);
}
function evaluateEvidence(id, data) {
  const levels = new Set(['UNKNOWN', 'D0', 'D1', 'D2', 'D3', 'D4', 'D5']);
  const level = data?.readiness?.level;
  if (!levels.has(level)) error(id + ': invalid readiness.level');
  const ev = asArray(data?.readiness?.evidence);
  if (['D3', 'D4', 'D5'].includes(level)) {
    if (!ev.length) error(id + ': '+ level +' requires linked acceptance evidence');
    for (const e of ev) {
      for (const k of ['scenario','revision_sha','environment','tenant','performed_at','result','business_readback','evidence_url']) {
        if (!e || typeof e[k] !== 'string' || !e[k].trim()) error(id + ': missing evidence field '+k);
      }
      if (!/^[a-f0-9]{40}$/.test(e?.revision_sha ?? '')) error(id + ': evidence must pin exact 40-char SHA');
      if (!/^https?:\/\//.test(e?.evidence_url ?? '')) error(id + ': evidence requires resolvable URL');
    }
  }
}
function checkPassport(id, moduleFile, manifest, coverage) {
  const target = path.join(docsRoot, id, 'passport.md');
  if (!fs.existsSync(target)) { error(id + ': missing docs/04-domains/'+id+'/passport.md'); return; }
  const source = fs.readFileSync(target,'utf8');
  const domainId = source.match(/^domain_id:\s*([^\s]+)\s*$/m)?.[1];
  if (domainId !== id) error(id + ': frontmatter domain_id mismatch');
  for (let i=0;i<required.length;i++) {
    const prefix = '## ' + (i+1) + '. ' + required[i];
    if (!source.split('\n').some(line => line.trim() === prefix)) error(id + ': missing section ' + prefix);
  }
  let data;
  try { data=machineBlock(source,id); } catch(e) { error(String(e.message)); return; }
  if (data.schema_version !== 1 || data.domain_id !== id) error(id + ': machine header mismatch');
  const relativeManifest = path.relative(root,moduleFile).split(path.sep).join('/');
  if (data.manifest !== relativeManifest) error(id + ': manifest path does not match source');
  if (!Array.isArray(data.components) || data.components.length === 0) error(id + ': at least one internal component required');
  const seen = new Set();
  const allowed = new Set([
    ...asArray(manifest.contributions?.capabilities),
    ...asArray(manifest.contributions?.capability_contracts).map(c => c.id)
  ]);
  for (const c of asArray(data.components)) {
    if (!c || typeof c.id !== 'string' || !/^[a-z][a-z0-9_]*$/.test(c.id)) {
      error(id + ': invalid component id'); continue;
    }
    if (seen.has(c.id)) error(id + ': duplicate component id '+c.id);
    seen.add(c.id);
    if (!Array.isArray(c.capability_ids)) { error(id+': component '+c.id+' needs capability_ids list'); continue; }
    for (const cap of c.capability_ids) if (!allowed.has(cap)) error(id + ': undeclared capability '+cap);
  }
  const expected = [...asArray(coverage?.processIds)].sort();
  const actual = [...asArray(data.process_ids)].sort();
  if (new Set(actual).size !== actual.length || JSON.stringify(expected)!==JSON.stringify(actual)) {
    error(id + ': process_ids drift; expected '+ expected.join(', '));
  }
  if (expected.length===0 && coverage?.status !== 'exempt') error(id + ': missing process or explicit exemption');
  evaluateEvidence(id, data);
}
if (process.argv.includes('--self-test')) {
  evaluateEvidence('fixture', {readiness:{level:'D4',evidence:[]}});
  if (!errors.some(x=>x.includes('requires linked acceptance evidence'))) throw new Error('D4 guard not working');
  errors.length=0;
  console.log('Domain Passport negative acceptance guard passed.');
  process.exit(0);
}
const coverage = buildDomainProcessCoverage();
const modules = fs.readdirSync(domainsRoot,{withFileTypes:true})
  .filter(e=>e.isDirectory() && fs.existsSync(path.join(domainsRoot,e.name,'module.php')));
let count=0;
for (const dir of modules) {
  const file = path.join(domainsRoot,dir.name,'module.php');
  try {
    const manifest=manifestAt(file);
    const id=manifest.id;
    if (!id || !coverage.domains[id]) { error(dir.name+': manifest ID not in process coverage'); continue; }
    checkPassport(id,file,manifest,coverage.domains[id]); count++;
  } catch(e) { error(dir.name+': '+ String(e.message).slice(0,350)); }
}
if (errors.length) {
  console.error('Domain Passport standard failed ('+errors.length+'):');
  for (const e of errors) console.error('- '+e);
  process.exit(1);
}
console.log('Domain Passport validation passed: '+count+' installable Domains; no readiness inferred from declarations.');
