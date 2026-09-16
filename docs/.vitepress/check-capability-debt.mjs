import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { loadRuntimeEvidence } from './process-runtime-evidence.mjs';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const debtFile = path.join(here, 'capability-debt.json');
const referenceIndex = path.join(docsRoot, '12-reference', 'README.md');
const catalogue = loadRuntimeEvidence();

const VALID_GAP_TYPES = new Set(['missing-domain-capability']);
const VALID_SEVERITIES = new Set(['high', 'medium', 'low']);
const VALID_RESOLUTIONS = new Set(['declare-domain-capability']);
const errors = [];

function fail(message) {
  errors.push(message);
}

function loadProcesses() {
  const definitions = new Map();
  for (const definition of loadProcessDefinitions()) definitions.set(definition.id, definition);
  return definitions;
}

function capabilityExists(domain, capability) {
  return catalogue.entries.some((entry) =>
    entry.type === 'capability'
    && entry.domain === domain
    && entry.ref === capability,
  );
}

if (!fs.existsSync(debtFile)) {
  console.error('Capability Debt Registry is missing.');
  process.exit(1);
}

const registry = JSON.parse(fs.readFileSync(debtFile, 'utf8'));
if (registry.schema_version !== 1) fail(`Capability Debt Registry schema_version must be 1, got '${registry.schema_version ?? 'missing'}'.`);
if (!Array.isArray(registry.items)) fail('Capability Debt Registry items must be an array.');

const processes = loadProcesses();
const gapSteps = new Map();
for (const definition of processes.values()) {
  for (const step of definition.steps ?? []) {
    if (step.capability === null && typeof step.capability_gap === 'string') {
      gapSteps.set(`${definition.id}:${step.id}`, { definition, step });
    }
  }
}

const seen = new Set();
let high = 0;
let medium = 0;
let low = 0;

for (const item of registry.items ?? []) {
  if (!item || typeof item !== 'object') {
    fail('Capability Debt Registry contains a non-object item.');
    continue;
  }

  const required = ['id', 'process_id', 'step_id', 'gap_type', 'owner_domain', 'severity', 'resolution', 'target_capability'];
  for (const key of required) {
    if (!item[key] || typeof item[key] !== 'string') fail(`Debt item '${item.id ?? 'unknown'}' requires string '${key}'.`);
  }

  const key = `${item.process_id}:${item.step_id}`;
  if (item.id !== key) fail(`Debt item '${item.id ?? 'unknown'}' id must equal '${key}'.`);
  if (seen.has(key)) fail(`Duplicate capability debt item '${key}'.`);
  seen.add(key);

  const gap = gapSteps.get(key);
  if (!gap) {
    fail(`Debt item '${key}' does not match a current Process Registry capability gap.`);
    continue;
  }

  const { step } = gap;
  if (!VALID_GAP_TYPES.has(item.gap_type)) fail(`Debt item '${key}' has unsupported gap_type '${item.gap_type}'.`);
  if (item.gap_type !== step.capability_gap) fail(`Debt item '${key}' gap_type does not match Process Registry '${step.capability_gap}'.`);
  if (item.owner_domain !== step.domain) fail(`Debt item '${key}' owner_domain '${item.owner_domain}' does not match step Domain '${step.domain}'.`);
  if (!VALID_SEVERITIES.has(item.severity)) fail(`Debt item '${key}' has unsupported severity '${item.severity}'.`);

  const expectedSeverity = step.critical === true ? 'high' : 'medium';
  if (item.severity !== expectedSeverity) fail(`Debt item '${key}' severity must be '${expectedSeverity}' for this canonical step.`);

  if (!VALID_RESOLUTIONS.has(item.resolution)) fail(`Debt item '${key}' has unsupported resolution '${item.resolution}'.`);
  if (!item.target_capability.startsWith(`${item.owner_domain}.`)) {
    fail(`Debt item '${key}' target capability '${item.target_capability}' is outside '${item.owner_domain}.*'.`);
  }
  if (capabilityExists(item.owner_domain, item.target_capability)) {
    fail(`Debt item '${key}' is stale because '${item.target_capability}' already exists in module capability authority.`);
  }

  if (item.severity === 'high') high += 1;
  if (item.severity === 'medium') medium += 1;
  if (item.severity === 'low') low += 1;
}

for (const key of gapSteps.keys()) {
  if (!seen.has(key)) fail(`Process Registry capability gap '${key}' has no Capability Debt Registry item.`);
}

if (fs.existsSync(referenceIndex)) {
  const index = fs.readFileSync(referenceIndex, 'utf8');
  if (!index.includes('Поточна максимальна Process Registry schema: **v5**.')) {
    fail('Reference Index must declare current Process Registry schema v5.');
  }
  if (!index.includes('Capability Debt Registry schema: **v1**.')) {
    fail('Reference Index must declare Capability Debt Registry schema v1.');
  }
} else {
  fail('Reference Index is missing.');
}

if (errors.length > 0) {
  console.error(`Capability Debt checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Capability Debt checks passed: ${seen.size}/${gapSteps.size} gaps tracked, ${high} high, ${medium} medium, ${low} low.`);
