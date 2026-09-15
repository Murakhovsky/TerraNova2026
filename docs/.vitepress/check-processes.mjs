import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const repoRoot = path.resolve(docsRoot, '..');
const registryRoot = path.join(here, 'processes');

const VALID_STATES = new Set(['as-is', 'to-be', 'runtime-verified']);
const VALID_STEP_KINDS = new Set(['operation', 'state', 'decision', 'outcome', 'manual']);
const VALID_MAPPING_TYPES = new Set(['use_case', 'command', 'event', 'source']);

const references = {
  use_case: fs.readFileSync(path.join(docsRoot, '12-reference/application-use-cases.md'), 'utf8'),
  command: fs.readFileSync(path.join(docsRoot, '12-reference/commands.md'), 'utf8'),
  event: fs.readFileSync(path.join(docsRoot, '12-reference/event-types.md'), 'utf8'),
};

const errors = [];
const processIds = new Set();
const workflowPaths = new Set();
let mappingCount = 0;
let criticalCount = 0;
let mappedCriticalCount = 0;

function fail(file, message) {
  errors.push(`${file}: ${message}`);
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

function exactReferenceExists(type, ref) {
  const source = references[type];
  return source.includes(`\`${ref}\``);
}

function validateRuntimeMapping(file, processId, stepId, mapping) {
  if (!mapping || typeof mapping !== 'object') {
    fail(file, `process '${processId}' step '${stepId}' contains an invalid runtime mapping`);
    return;
  }

  if (!VALID_MAPPING_TYPES.has(mapping.type)) {
    fail(file, `process '${processId}' step '${stepId}' uses unsupported mapping type '${mapping.type ?? 'missing'}'`);
    return;
  }

  mappingCount += 1;

  if (mapping.type === 'source') {
    if (!mapping.path || typeof mapping.path !== 'string') {
      fail(file, `process '${processId}' step '${stepId}' source mapping requires 'path'`);
      return;
    }

    const absolute = path.join(repoRoot, mapping.path);
    if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) {
      fail(file, `process '${processId}' step '${stepId}' source path does not exist: ${mapping.path}`);
      return;
    }

    if (mapping.symbol) {
      const source = fs.readFileSync(absolute, 'utf8');
      if (!source.includes(mapping.symbol)) {
        fail(file, `process '${processId}' step '${stepId}' source ${mapping.path} does not contain symbol '${mapping.symbol}'`);
      }
    }
    return;
  }

  if (!mapping.ref || typeof mapping.ref !== 'string') {
    fail(file, `process '${processId}' step '${stepId}' ${mapping.type} mapping requires 'ref'`);
    return;
  }

  if (!exactReferenceExists(mapping.type, mapping.ref)) {
    fail(file, `process '${processId}' step '${stepId}' references missing ${mapping.type} '${mapping.ref}'`);
  }
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

  if (definition.schema_version !== 1) fail(file, `schema_version must be 1, got '${definition.schema_version ?? 'missing'}'`);
  if (!definition.id || typeof definition.id !== 'string') fail(file, 'id is required');
  if (!definition.title || typeof definition.title !== 'string') fail(file, 'title is required');
  if (!definition.domain || typeof definition.domain !== 'string') fail(file, 'domain is required');
  if (!VALID_STATES.has(definition.state)) fail(file, `state must be one of ${[...VALID_STATES].join(', ')}`);
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
      const frontmatter = parseFrontmatter(fs.readFileSync(workflowFile, 'utf8'));
      if (frontmatter.get('contract') !== 'workflow-v2') {
        fail(file, `workflow ${definition.workflow} must use contract workflow-v2`);
      }
      if (frontmatter.get('process_state') !== definition.state) {
        fail(file, `workflow process_state '${frontmatter.get('process_state') ?? 'missing'}' does not match registry state '${definition.state}'`);
      }
      if (frontmatter.get('title') !== definition.title) {
        fail(file, `workflow title '${frontmatter.get('title') ?? 'missing'}' does not match registry title '${definition.title}'`);
      }
    }
  }

  if (!Array.isArray(definition.actors) || definition.actors.length === 0) fail(file, 'actors must be a non-empty array');
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
    if (!step.id || typeof step.id !== 'string') {
      fail(file, `process '${definition.id}' has a step without id`);
      continue;
    }
    if (stepIds.has(step.id)) fail(file, `process '${definition.id}' has duplicate step id '${step.id}'`);
    stepIds.add(step.id);

    if (!step.label || typeof step.label !== 'string') fail(file, `process '${definition.id}' step '${step.id}' requires label`);
    if (!VALID_STEP_KINDS.has(step.kind)) fail(file, `process '${definition.id}' step '${step.id}' has unsupported kind '${step.kind ?? 'missing'}'`);

    const runtime = step.runtime ?? [];
    if (!Array.isArray(runtime)) {
      fail(file, `process '${definition.id}' step '${step.id}' runtime must be an array`);
      continue;
    }

    if (step.critical === true) {
      criticalCount += 1;
      if (runtime.length > 0) mappedCriticalCount += 1;
      if (definition.state === 'runtime-verified' && runtime.length === 0) {
        fail(file, `runtime-verified process '${definition.id}' critical step '${step.id}' has no runtime mapping`);
      }
    }

    for (const mapping of runtime) validateRuntimeMapping(file, definition.id, step.id, mapping);
  }

  const edgeKeys = new Set();
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

console.log(`Process Registry checks passed: ${files.length} processes, ${mappingCount} runtime mappings, ${mappedCriticalCount}/${criticalCount} critical steps mapped.`);
