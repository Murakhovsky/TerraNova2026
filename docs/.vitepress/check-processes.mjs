import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  loadRuntimeEvidence,
  processVerification,
  resolveRuntimeMapping,
} from './process-runtime-evidence.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const registryRoot = path.join(here, 'processes');

const VALID_STATES = new Set(['as-is', 'to-be']);
const VALID_STEP_KINDS = new Set(['operation', 'state', 'decision', 'outcome', 'manual']);
const VALID_MAPPING_TYPES = new Set(['use_case', 'command', 'event', 'contract', 'source']);
const catalogue = loadRuntimeEvidence();

const errors = [];
const processIds = new Set();
const workflowPaths = new Set();
let mappingCount = 0;
let verifiedMappingCount = 0;
let stepCount = 0;
let ownedStepCount = 0;
let mappedStepCount = 0;
let verifiedStepCount = 0;
let criticalCount = 0;
let sourceVerifiedCriticalCount = 0;
let runtimeVerifiedCriticalCount = 0;

function fail(file, message) {
  errors.push(`${file}: ${message}`);
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function parseFrontmatter(content) {
  const normalized = content.replace(/\r\n/g, '\n');
  if (!normalized.startsWith('---\n')) return new Map();
  const lines = normalized.split('\n');
  const end = lines.indexOf('---', 1);
  if (end === -1) return new Map();

  const values = new Map();
  for (let index = 1; index < end; index += 1) {
    const match = lines[index].match(/^([A-Za-z0-9_-]+):\s*(.*)$/);
    if (match) values.set(match[1], match[2].trim().replace(/^['"]|['"]$/g, ''));
  }
  return values;
}

function validateRuntimeMapping(file, definition, step, mapping) {
  if (!mapping || typeof mapping !== 'object') {
    fail(file, `process '${definition.id}' step '${step.id}' contains an invalid runtime mapping`);
    return;
  }

  if (!VALID_MAPPING_TYPES.has(mapping.type)) {
    fail(file, `process '${definition.id}' step '${step.id}' uses unsupported mapping type '${mapping.type ?? 'missing'}'`);
    return;
  }

  mappingCount += 1;
  const resolution = resolveRuntimeMapping(mapping, catalogue, definition.domain);
  if (!resolution.verified) {
    fail(file, `process '${definition.id}' step '${step.id}' has unresolved ${mapping.type} mapping: ${resolution.reason}`);
    return;
  }
  verifiedMappingCount += 1;
}

if (!fs.existsSync(registryRoot)) {
  console.error(`Process registry directory is missing: ${registryRoot}`);
  process.exit(1);
}

const files = fs.readdirSync(registryRoot)
  .filter((name) => name.endsWith('.json'))
  .sort();

if (files.length === 0) {
  console.error('Process registry is empty.');
  process.exit(1);
}

for (const name of files) {
  const file = `.vitepress/processes/${name}`;
  const absolute = path.join(registryRoot, name);
  let definition;

  try {
    definition = JSON.parse(fs.readFileSync(absolute, 'utf8'));
  } catch (error) {
    fail(file, `invalid JSON: ${error instanceof Error ? error.message : String(error)}`);
    continue;
  }

  if (definition.schema_version !== 3) fail(file, `schema_version must be 3, got '${definition.schema_version ?? 'missing'}'`);
  if (!definition.id || typeof definition.id !== 'string') fail(file, 'id is required');
  if (!definition.title || typeof definition.title !== 'string') fail(file, 'title is required');
  if (!definition.domain || typeof definition.domain !== 'string') fail(file, 'domain is required');
  if (!VALID_STATES.has(definition.state)) fail(file, `state must be one of ${[...VALID_STATES].join(', ')}`);
  if (Object.prototype.hasOwnProperty.call(definition, 'verification')) {
    fail(file, 'verification is derived from runtime evidence and must not be authored');
  }
  if (!definition.workflow || typeof definition.workflow !== 'string') fail(file, 'workflow is required');

  if (definition.id) {
    if (processIds.has(definition.id)) fail(file, `duplicate process id '${definition.id}'`);
    processIds.add(definition.id);
  }

  if (definition.workflow) {
    if (workflowPaths.has(definition.workflow)) fail(file, `duplicate workflow mapping '${definition.workflow}'`);
    workflowPaths.add(definition.workflow);

    const workflowFile = path.join(docsRoot, definition.workflow);
    if (!fs.existsSync(workflowFile)) {
      fail(file, `workflow page does not exist: ${definition.workflow}`);
    } else {
      const workflowContent = fs.readFileSync(workflowFile, 'utf8');
      const frontmatter = parseFrontmatter(workflowContent);
      if (frontmatter.get('contract') !== 'workflow-v2') {
        fail(file, `workflow ${definition.workflow} must use contract workflow-v2`);
      }
      if (frontmatter.get('process_state') !== definition.state) {
        fail(file, `workflow process_state '${frontmatter.get('process_state') ?? 'missing'}' does not match registry state '${definition.state}'`);
      }
      if (frontmatter.get('process_id') !== definition.id) {
        fail(file, `workflow process_id '${frontmatter.get('process_id') ?? 'missing'}' does not match registry id '${definition.id}'`);
      }
      if (frontmatter.get('title') !== definition.title) {
        fail(file, `workflow title '${frontmatter.get('title') ?? 'missing'}' does not match registry title '${definition.title}'`);
      }
      const processId = escapeRegExp(definition.id);
      const flowPattern = new RegExp(`<ProcessDiagram\\s+process-id=["']${processId}["']\\s*/>`);
      if (!flowPattern.test(workflowContent)) {
        fail(file, `workflow ${definition.workflow} must render ProcessDiagram for '${definition.id}'`);
      }
      const ownershipPattern = new RegExp(`<ProcessDiagram(?=[^>]*process-id=["']${processId}["'])(?=[^>]*view=["']ownership["'])[^>]*/>`);
      if (!ownershipPattern.test(workflowContent)) {
        fail(file, `workflow ${definition.workflow} must render ownership view for '${definition.id}'`);
      }
    }
  }

  if (!Array.isArray(definition.actors) || definition.actors.length === 0) {
    fail(file, 'actors must be a non-empty array');
  }
  const actors = new Set(Array.isArray(definition.actors) ? definition.actors : []);
  if (actors.size !== (definition.actors?.length ?? 0)) fail(file, 'actors must not contain duplicates');

  if (!Array.isArray(definition.outcomes) || definition.outcomes.length === 0) fail(file, 'outcomes must be a non-empty array');
  if (!Array.isArray(definition.steps) || definition.steps.length === 0) {
    fail(file, 'steps must be a non-empty array');
    continue;
  }
  if (!Array.isArray(definition.edges)) {
    fail(file, 'edges must be an array');
    continue;
  }

  const stepIds = new Set();
  for (const step of definition.steps) {
    stepCount += 1;
    if (!step.id || typeof step.id !== 'string') {
      fail(file, `process '${definition.id}' has a step without id`);
      continue;
    }
    if (stepIds.has(step.id)) fail(file, `process '${definition.id}' has duplicate step id '${step.id}'`);
    stepIds.add(step.id);

    if (!step.label || typeof step.label !== 'string') fail(file, `process '${definition.id}' step '${step.id}' requires label`);
    if (!VALID_STEP_KINDS.has(step.kind)) fail(file, `process '${definition.id}' step '${step.id}' has unsupported kind '${step.kind ?? 'missing'}'`);

    if (!step.owner || typeof step.owner !== 'string') {
      fail(file, `process '${definition.id}' step '${step.id}' requires owner`);
    } else if (!actors.has(step.owner)) {
      fail(file, `process '${definition.id}' step '${step.id}' owner '${step.owner}' is not declared in actors`);
    } else {
      ownedStepCount += 1;
    }

    const runtime = step.runtime ?? [];
    if (!Array.isArray(runtime)) {
      fail(file, `process '${definition.id}' step '${step.id}' runtime must be an array`);
      continue;
    }
    if (runtime.length > 0) mappedStepCount += 1;

    for (const mapping of runtime) validateRuntimeMapping(file, definition, step, mapping);
  }

  const verification = processVerification(definition, catalogue);
  verifiedStepCount += verification.verifiedSteps;
  criticalCount += verification.critical;
  sourceVerifiedCriticalCount += verification.criticalSourceVerified;
  runtimeVerifiedCriticalCount += verification.criticalRuntimeVerified;

  const edgeKeys = new Set();
  const incoming = new Map([...stepIds].map((id) => [id, 0]));
  const outgoing = new Map([...stepIds].map((id) => [id, []]));

  for (const edge of definition.edges) {
    if (!edge || typeof edge !== 'object' || !edge.from || !edge.to) {
      fail(file, `process '${definition.id}' contains an invalid edge`);
      continue;
    }
    if (!stepIds.has(edge.from)) fail(file, `edge references unknown source step '${edge.from}'`);
    if (!stepIds.has(edge.to)) fail(file, `edge references unknown target step '${edge.to}'`);
    const edgeKey = `${edge.from}->${edge.to}:${edge.label ?? ''}`;
    if (edgeKeys.has(edgeKey)) fail(file, `duplicate edge '${edgeKey}'`);
    edgeKeys.add(edgeKey);

    if (stepIds.has(edge.from) && stepIds.has(edge.to)) {
      incoming.set(edge.to, (incoming.get(edge.to) ?? 0) + 1);
      outgoing.get(edge.from)?.push(edge.to);
    }
  }

  const roots = [...stepIds].filter((id) => (incoming.get(id) ?? 0) === 0);
  const terminals = [...stepIds].filter((id) => (outgoing.get(id) ?? []).length === 0);
  if (roots.length === 0) fail(file, `process '${definition.id}' has no root step`);
  if (terminals.length === 0) fail(file, `process '${definition.id}' has no terminal step`);

  const reachable = new Set();
  const queue = [...roots];
  while (queue.length > 0) {
    const current = queue.shift();
    if (reachable.has(current)) continue;
    reachable.add(current);
    for (const next of outgoing.get(current) ?? []) queue.push(next);
  }
  for (const stepId of stepIds) {
    if (!reachable.has(stepId)) fail(file, `process '${definition.id}' step '${stepId}' is not reachable from a root step`);
  }
}

for (const entry of fs.readdirSync(path.join(docsRoot, '02-workflows'), { withFileTypes: true })) {
  if (!entry.isFile() || !entry.name.endsWith('.md')) continue;
  const workflow = `02-workflows/${entry.name}`;
  const frontmatter = parseFrontmatter(fs.readFileSync(path.join(docsRoot, workflow), 'utf8'));
  if (frontmatter.get('contract') === 'workflow-v2' && !workflowPaths.has(workflow)) {
    errors.push(`${workflow}: workflow-v2 page has no Process Registry definition`);
  }
}

if (errors.length > 0) {
  console.error(`Process Registry checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Process Registry checks passed: ${files.length} processes, ${ownedStepCount}/${stepCount} steps owned, ${mappedStepCount}/${stepCount} steps mapped, ${verifiedStepCount}/${stepCount} steps evidence-verified, ${verifiedMappingCount}/${mappingCount} mappings verified, ${sourceVerifiedCriticalCount}/${criticalCount} critical steps source-verified, ${runtimeVerifiedCriticalCount}/${criticalCount} critical steps runtime-verified.`);
