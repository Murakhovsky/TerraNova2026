import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildCrossDomainProcessTopology } from './cross-domain-process-topology.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const errors = [];
const topology = buildCrossDomainProcessTopology();

function fail(message) {
  errors.push(message);
}

if (topology.schemaVersion !== 1) fail(`Cross-domain topology schema must be v1, got '${topology.schemaVersion}'.`);
if (topology.authority !== 'process-registry+runtime-evidence') fail(`Unexpected cross-domain topology authority '${topology.authority}'.`);
if (topology.crossDomainStepCount !== topology.hops.length) fail('Cross-domain step count must equal hop count.');
if (topology.boundaryCount !== topology.boundaries.length) fail('Boundary count must equal boundary collection size.');
if (topology.crossDomainProcessCount !== topology.crossDomainProcesses.length) fail('Cross-domain process count must equal unique process collection size.');

const hopIds = new Set();
for (const hop of topology.hops) {
  if (hopIds.has(hop.id)) fail(`Duplicate cross-domain hop '${hop.id}'.`);
  hopIds.add(hop.id);
  if (!hop.processId || !hop.stepId) fail(`Cross-domain hop '${hop.id ?? 'unknown'}' requires process and step ids.`);
  if (!hop.processDomain || !hop.targetDomain || hop.processDomain === hop.targetDomain) fail(`Cross-domain hop '${hop.id}' must cross two distinct Domains.`);
  if (!hop.contract) fail(`Cross-domain hop '${hop.id}' requires a contract.`);
  if (hop.evidenceRole !== 'requires') fail(`Cross-domain hop '${hop.id}' contract evidence must have role 'requires'.`);
  if (hop.evidenceStrength !== 'runtime') fail(`Cross-domain hop '${hop.id}' contract evidence must be runtime-strength.`);
  if (!hop.capability || !hop.capability.startsWith(`${hop.targetDomain}.`)) fail(`Cross-domain hop '${hop.id}' must resolve a target-Domain capability.`);
}

for (const boundary of topology.boundaries) {
  if (boundary.processCount < 1 || boundary.stepCount < 1) fail(`Boundary '${boundary.id}' must aggregate at least one process step.`);
  if (boundary.processIds.length !== boundary.processCount) fail(`Boundary '${boundary.id}' process count is inconsistent.`);
  if (boundary.stepIds.length !== boundary.stepCount) fail(`Boundary '${boundary.id}' step count is inconsistent.`);
}

const referenceIndex = path.join(docsRoot, '12-reference', 'README.md');
if (!fs.existsSync(referenceIndex)) {
  fail('Reference Index is missing.');
} else if (!fs.readFileSync(referenceIndex, 'utf8').includes('[Cross-Domain Process Topology](cross-domain-process-topology.md)')) {
  fail('Reference Index must link generated Cross-Domain Process Topology.');
}

const conceptPage = path.join(docsRoot, '02-workflows', 'cross-domain-process-topology.md');
if (!fs.existsSync(conceptPage)) {
  fail('Cross-Domain Process Topology concept page is missing.');
} else {
  const source = fs.readFileSync(conceptPage, 'utf8');
  if (!source.includes('<CrossDomainProcessTopology />')) fail('Cross-domain topology concept page must render CrossDomainProcessTopology.');
  if (!source.includes('../12-reference/cross-domain-process-topology.md')) fail('Cross-domain topology concept page must link the generated reference.');
}

const themeIndex = path.join(here, 'theme', 'index.mjs');
const themeSource = fs.readFileSync(themeIndex, 'utf8');
if (!themeSource.includes("import CrossDomainProcessTopology from './CrossDomainProcessTopology.vue';")) {
  fail('VitePress theme must import CrossDomainProcessTopology.');
}
if (!themeSource.includes("app.component('CrossDomainProcessTopology', CrossDomainProcessTopology)")) {
  fail('VitePress theme must register CrossDomainProcessTopology.');
}

if (errors.length > 0) {
  console.error(`Cross-Domain Process Topology checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Cross-Domain Process Topology checks passed: ${topology.crossDomainProcessCount} processes, ${topology.crossDomainStepCount} hops, ${topology.boundaryCount} boundaries.`);
