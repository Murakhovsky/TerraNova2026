import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { loadEntityStateRegistry } from './entity-state-registry.mjs';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const domainsRoot = path.join(repoRoot, 'app', 'Domains');
const allowedRepresentations = new Set(['domain-model', 'application-persisted', 'association-record']);

function fail(message) {
  console.error(`Entity & State Registry: ${message}`);
  process.exitCode = 1;
}

function moduleIds() {
  const ids = new Set();
  if (!fs.existsSync(domainsRoot)) return ids;
  for (const entry of fs.readdirSync(domainsRoot, { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const manifest = path.join(domainsRoot, entry.name, 'module.php');
    if (!fs.existsSync(manifest)) continue;
    const source = fs.readFileSync(manifest, 'utf8');
    const id = source.match(/['\"]id['\"]\s*=>\s*['\"]([^'\"]+)['\"]/)?.[1];
    if (id) ids.add(id);
  }
  return ids;
}

function resolveRepoPath(relativePath, label) {
  if (typeof relativePath !== 'string' || relativePath.trim() === '') {
    fail(`${label} must be a non-empty repository path.`);
    return null;
  }
  const absolute = path.resolve(repoRoot, relativePath);
  if (!absolute.startsWith(repoRoot + path.sep)) {
    fail(`${label} escapes repository root: ${relativePath}`);
    return null;
  }
  if (!fs.existsSync(absolute)) {
    fail(`${label} does not exist: ${relativePath}`);
    return null;
  }
  return absolute;
}

function assertSource(pathValue, symbol, label) {
  const absolute = resolveRepoPath(pathValue, label);
  if (!absolute) return null;
  const source = fs.readFileSync(absolute, 'utf8');
  if (typeof symbol !== 'string' || symbol.trim() === '') {
    fail(`${label} symbol must be non-empty.`);
  } else if (!source.includes(symbol)) {
    fail(`${label} symbol not found: ${symbol} in ${pathValue}`);
  }
  return source;
}

function parseStateValues(source) {
  const enumValues = [...source.matchAll(/\bcase\s+[A-Za-z_][A-Za-z0-9_]*\s*=\s*['\"]([^'\"]+)['\"]\s*;/g)].map((match) => match[1]);
  if (enumValues.length > 0) return enumValues;
  return [...source.matchAll(/\bpublic\s+const\s+[A-Z][A-Z0-9_]*\s*=\s*['\"]([^'\"]+)['\"]\s*;/g)].map((match) => match[1]);
}

function sameSet(a, b) {
  const left = [...new Set(a)].sort();
  const right = [...new Set(b)].sort();
  return left.length === right.length && left.every((value, index) => value === right[index]);
}

const registry = loadEntityStateRegistry();
const definitions = loadProcessDefinitions();
const installed = moduleIds();
const entityIds = new Set();
const processSteps = new Map();
for (const definition of definitions) {
  for (const step of definition.steps ?? []) {
    processSteps.set(`${definition.id}:${step.id}`, { process: definition, step });
  }
}

if (registry.schema_version !== 1) fail(`schema_version must be 1, got ${registry.schema_version}.`);
if (!/^[0-9a-f]{40}$/.test(registry.reviewed_main_revision ?? '')) fail('reviewed_main_revision must be a 40-character lowercase commit SHA.');
if (!Array.isArray(registry.entities) || registry.entities.length === 0) fail('entities must be a non-empty array.');

let stateModels = 0;
let touchpoints = 0;

for (const entity of registry.entities ?? []) {
  const label = entity.id ?? '<missing-id>';
  if (typeof entity.id !== 'string' || entity.id.trim() === '') {
    fail('every entity requires an id.');
    continue;
  }
  if (entityIds.has(entity.id)) fail(`duplicate entity id: ${entity.id}`);
  entityIds.add(entity.id);

  if (!installed.has(entity.domain)) fail(`${label}: domain ${entity.domain} is not an installable module.`);
  if (!entity.id.startsWith(`${entity.domain}.`)) fail(`${label}: entity id must use the owner Domain namespace ${entity.domain}.`);
  if (!allowedRepresentations.has(entity.representation)) fail(`${label}: invalid representation ${entity.representation}.`);
  if (typeof entity.identity !== 'string' || entity.identity.trim() === '') fail(`${label}: identity description is required.`);

  const source = entity.source ?? {};
  assertSource(source.path, source.symbol, `${label} entity source`);
  if (typeof source.path === 'string' && !source.path.startsWith(`app/Domains/${entity.domain[0].toUpperCase()}${entity.domain.slice(1)}/`)) {
    fail(`${label}: entity source must stay inside its owner Domain directory.`);
  }

  if (!entity.state || typeof entity.state !== 'object') {
    fail(`${label}: canonical entity requires an explicit state model.`);
  } else {
    stateModels += 1;
    const stateSource = assertSource(entity.state.source_path, entity.state.symbol, `${label} state source`);
    if (typeof entity.state.name !== 'string' || entity.state.name.trim() === '') fail(`${label}: state name is required.`);
    if (typeof entity.state.semantics !== 'string' || entity.state.semantics.trim() === '') fail(`${label}: state semantics are required.`);
    if (!Array.isArray(entity.state.values) || entity.state.values.length === 0) fail(`${label}: state values must be non-empty.`);
    if (new Set(entity.state.values ?? []).size !== (entity.state.values ?? []).length) fail(`${label}: state values contain duplicates.`);
    if (stateSource) {
      const parsed = parseStateValues(stateSource);
      if (parsed.length === 0) fail(`${label}: no enum cases or public string constants found in ${entity.state.source_path}.`);
      else if (!sameSet(parsed, entity.state.values ?? [])) {
        fail(`${label}: registry state values [${(entity.state.values ?? []).join(', ')}] do not match source [${parsed.join(', ')}].`);
      }
    }
    for (const key of ['terminal_values', 'closed_values']) {
      if (entity.state[key] === undefined) continue;
      if (!Array.isArray(entity.state[key])) fail(`${label}: ${key} must be an array.`);
      else for (const value of entity.state[key]) if (!(entity.state.values ?? []).includes(value)) fail(`${label}: ${key} contains unknown state ${value}.`);
    }
  }

  if (!Array.isArray(entity.process_steps) || entity.process_steps.length === 0) {
    fail(`${label}: at least one canonical process touchpoint is required.`);
  } else {
    const local = new Set();
    for (const ref of entity.process_steps) {
      touchpoints += 1;
      if (local.has(ref)) fail(`${label}: duplicate process touchpoint ${ref}.`);
      local.add(ref);
      const resolved = processSteps.get(ref);
      if (!resolved) {
        fail(`${label}: unknown process step ${ref}.`);
        continue;
      }
      if (resolved.step.domain !== entity.domain) {
        fail(`${label}: process touchpoint ${ref} belongs to Domain ${resolved.step.domain}, not ${entity.domain}.`);
      }
    }
  }
}

if (process.exitCode) process.exit(process.exitCode);
console.log(`Entity & State Registry checks passed: ${entityIds.size} entities, ${stateModels} state models, ${installed.size} installable Domains, ${touchpoints} process touchpoints, reviewed main ${registry.reviewed_main_revision.slice(0, 8)}.`);
