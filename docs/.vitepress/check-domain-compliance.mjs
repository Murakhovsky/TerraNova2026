import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const domainsDir = path.join(root, 'app', 'Domains');
const docsDir = path.join(root, 'docs', '04-domains');
const baseline = JSON.parse(fs.readFileSync(path.join(root, 'docs/.vitepress/das-001-compliance-baseline.json'), 'utf8'));
const debt = JSON.parse(fs.readFileSync(path.join(root, 'docs/.vitepress/capability-debt.json'), 'utf8'));
const issues = [];
const rows = [];
const asList = value => Array.isArray(value) ? value : [];
const nonempty = value => typeof value === 'string' && value.trim().length > 0;
const report = (id, message) => issues.push(id + ': ' + message);
const exists = relative => typeof relative === 'string' && !relative.includes('..') && fs.existsSync(path.join(root, relative));

function loadManifest(file) {
  const raw = execFileSync('php', ['-r', 'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', file], {
    cwd: root, encoding: 'utf8', timeout: 10000
  });
  return JSON.parse(raw);
}

function loadPassport(id) {
  const file = path.join(docsDir, id, 'passport.md');
  if (!fs.existsSync(file)) { report(id, 'missing Domain Passport'); return null; }
  const source = fs.readFileSync(file, 'utf8');
  const match = source.match(/<!-- COS-DOMAIN-PASSPORT-DATA\s*([\s\S]*?)\s*COS-DOMAIN-PASSPORT-DATA -->/);
  if (!match) { report(id, 'missing machine-readable passport'); return null; }
  try { return JSON.parse(match[1]); }
  catch (error) { report(id, 'invalid passport JSON: ' + error.message); return null; }
}

function coreContractErrors(domain, contract) {
  const errors = [];
  const id = contract?.id;
  if (!nonempty(id) || !id.startsWith(domain + '.')) errors.push('invalid or foreign capability ID');
  if (contract?.owner_domain !== domain) errors.push('owner_domain mismatch');
  if (!nonempty(contract?.version)) errors.push('missing version');
  if (!['query', 'command'].includes(contract?.kind)) errors.push('invalid kind');
  if (!nonempty(contract?.execution_binding) || !/^(query|read|command|action):[a-z0-9_.-]+$/i.test(contract.execution_binding)) {
    errors.push('missing/invalid execution binding');
  }
  if (!nonempty(contract?.permission)) errors.push('missing tenant permission');
  if (!nonempty(contract?.input_schema) || !nonempty(contract?.output_schema)) errors.push('missing typed schema path');
  if (!nonempty(contract?.lifecycle)) errors.push('missing lifecycle');
  if (!Array.isArray(contract?.tests) || !contract.tests.length) errors.push('missing test references');
  if (contract?.kind === 'query' && contract?.side_effect_level !== 'none') errors.push('query claims side effects');
  if (contract?.execution_binding?.startsWith('action:') &&
      ['external', 'financial'].includes(contract?.side_effect_level) &&
      (contract?.approval_policy !== 'required' || contract?.idempotency !== 'required')) {
    errors.push('governed Action lacks required approval/idempotency');
  }
  return errors;
}

function checkSchema(domain, id, field, file) {
  if (!exists(file)) { report(domain, id + ': missing ' + field + ' ' + file); return; }
  try {
    const schema = JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));
    if (schema.type !== 'object') report(domain, id + ': ' + field + ' must declare an object schema');
  } catch (error) { report(domain, id + ': invalid ' + field + ': ' + error.message); }
}

function inspect(dir) {
  const source = path.join(domainsDir, dir, 'module.php');
  const module = loadManifest(source);
  const id = module.id;
  if (!nonempty(id)) { report(dir, 'missing module.id'); return; }
  const policy = baseline.domains?.[id];
  if (!policy) report(id, 'no reviewed DAS-001 baseline; new Domains require explicit debt review');
  const contribution = module.contributions ?? {};
  const descriptive = asList(contribution.capabilities);
  const typed = asList(contribution.capability_contracts);
  const typedIds = new Set(typed.map(contract => contract?.id));
  const legacy = descriptive.filter(capability => !typedIds.has(capability));
  const gapItems = asList(debt.items).filter(item => item.owner_domain === id);
  const passport = loadPassport(id);
  const mapped = new Set(asList(passport?.components).flatMap(component => asList(component.capability_ids)));

  if (new Set(descriptive).size !== descriptive.length) report(id, 'duplicate descriptive capability IDs');
  if (typedIds.size !== typed.length) report(id, 'duplicate typed Capability IDs');
  for (const contract of typed) {
    for (const error of coreContractErrors(id, contract)) report(id, String(contract?.id ?? '?') + ': ' + error);
    if (!descriptive.includes(contract?.id)) report(id, 'typed capability not published in contributions.capabilities: ' + contract?.id);
    for (const field of ['input_schema', 'output_schema']) {
      if (nonempty(contract?.[field])) checkSchema(id, contract.id, field, contract[field]);
    }
    for (const test of asList(contract.tests)) {
      if (!exists(test)) report(id, contract.id + ': missing test reference ' + test);
    }
  }
  for (const item of gapItems) {
    if (descriptive.includes(item.target_capability) || typedIds.has(item.target_capability)) {
      report(id, 'stale process capability debt: ' + item.id);
    }
  }
  if (policy) {
    if (typed.length < policy.min_typed) report(id, 'typed contract floor regressed: ' + typed.length + ' < ' + policy.min_typed);
    if (legacy.length > policy.max_descriptive_only) report(id, 'new descriptive-only capability debt: ' + legacy.length + ' > ' + policy.max_descriptive_only);
    if (gapItems.length > policy.max_process_gaps) report(id, 'process capability gap debt increased: ' + gapItems.length + ' > ' + policy.max_process_gaps);
  }
  const unmappedTyped = typed.filter(contract => !mapped.has(contract?.id)).map(contract => contract.id);
  // DAS-001: every typed contract needs exactly one accountable component. A
  // declaration missing from the passport can no longer silently pass CI.
  for (const id of unmappedTyped) report(module.id, 'typed capability has no owner component mapping: ' + id);
  const componentCounts = new Map();
  for (const component of asList(passport?.components)) {
    for (const id of asList(component?.capability_ids)) {
      componentCounts.set(id, (componentCounts.get(id) ?? 0) + 1);
    }
  }
  for (const contract of typed) {
    if ((componentCounts.get(contract.id) ?? 0) > 1) {
      report(module.id, 'typed capability assigned to multiple components: ' + contract.id);
    }
  }
  if (passport && passport.domain_id !== id) report(id, 'passport ID mismatch');
  if (passport && passport.manifest !== 'app/Domains/' + dir + '/module.php') report(id, 'passport manifest mismatch');
  if (passport && !Array.isArray(passport.components)) report(id, 'missing passport component map');
  rows.push({ id, typed: typed.length, descriptive_only: legacy.length, process_gaps: gapItems.length,
    mapped_typed: typed.length - unmappedTyped.length, unmapped_typed: unmappedTyped,
    readiness: passport?.readiness?.level ?? 'UNKNOWN',
    runtime_bound: 'NOT_ASSESSED', outcome_verified: 'NOT_ASSESSED' });
}

function selfTest() {
  const sample = {id:'demo.act', owner_domain:'demo', kind:'command', version:'1.0.0',
    execution_binding:'action:demo.act', permission:'cos.tenant.manage',
    input_schema:'schema.json', output_schema:'schema.json', lifecycle:'experimental',
    tests:['test.php'], side_effect_level:'external', approval_policy:'required', idempotency:'required' };
  if (coreContractErrors('demo', sample).length) throw new Error('valid sample rejected');
  if (!coreContractErrors('other', sample).includes('owner_domain mismatch')) throw new Error('foreign ownership undetected');
  if (!coreContractErrors('demo', {...sample, approval_policy:'none'}).some(e=>e.includes('approval'))) throw new Error('unapproved Action undetected');
  if (!coreContractErrors('demo', {...sample, execution_binding:''}).some(e=>e.includes('binding'))) throw new Error('unbound contract undetected');
  console.log('DAS-001 compliance gate negative fixtures PASS');
}

if (process.argv.includes('--self-test')) {
  selfTest();
} else {
  if (baseline.schema_version !== 1 || debt.schema_version !== 1) report('registry', 'unsupported baseline or debt schema version');
  const dirs = fs.readdirSync(domainsDir, {withFileTypes: true})
    .filter(entry => entry.isDirectory() && fs.existsSync(path.join(domainsDir, entry.name, 'module.php')))
    .map(entry => entry.name).sort();
  for (const dir of dirs) {
    try { inspect(dir); }
    catch (error) { report(dir, 'cannot inspect module: ' + error.message); }
  }
  const output = { standard: 'DAS-001', source: 'Module manifests + Process debt + Domain passports',
    warning: 'Typed != bound != authorized != verified outcome', domains: rows, violations: issues };
  if (process.argv.includes('--json')) console.log(JSON.stringify(output, null, 2));
  else {
    for (const row of rows) console.log([row.id, 'typed='+row.typed,
      'descriptive_only='+row.descriptive_only, 'process_gaps='+row.process_gaps,
      'mapped_typed='+row.mapped_typed+'/'+row.typed, 'readiness='+row.readiness].join(' '));
    console.log('DAS-001 compliance: ' + rows.length + ' Domains checked; ' + issues.length + ' blocking regressions.');
    for (const issue of issues) console.error('- ' + issue);
  }
  if (issues.length) process.exitCode = 1;
}
