import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildKnowledgeHealth } from './knowledge-health.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const errors = [];
const health = buildKnowledgeHealth();
const fail = (message) => errors.push(message);

if (health.authority !== 'structured-current-checkout') fail(`Unexpected knowledge-health authority '${health.authority}'.`);
if (health.totalProcesses <= 0) fail('Knowledge Health must contain at least one canonical process.');
if (health.totalSteps <= 0) fail('Knowledge Health must contain canonical process steps.');
if (health.capabilityMappedSteps + health.capabilityGapSteps !== health.totalSteps) fail('Capability mapped steps + capability gaps must equal total process steps.');
if (health.debtItems !== health.capabilityGapSteps) fail(`Capability debt must track every gap exactly: debt=${health.debtItems}, gaps=${health.capabilityGapSteps}.`);
if (health.evidenceVerifiedSteps > health.totalSteps || health.runtimeVerifiedSteps > health.totalSteps) fail('Evidence coverage cannot exceed total process steps.');
if (health.criticalSourceVerified > health.criticalSteps || health.criticalRuntimeVerified > health.criticalSteps) fail('Critical verification cannot exceed critical step count.');
if (!health.processSchemaVersions.includes(5)) fail('Knowledge Health must expose current Process Registry schema v5.');
if (health.debtSchemaVersion !== 1) fail(`Knowledge Health must expose Capability Debt schema v1, got '${health.debtSchemaVersion}'.`);

const topology = health.crossDomainTopology;
if (!topology || typeof topology !== 'object') {
  fail('Knowledge Health must expose Cross-Domain Process Topology summary.');
} else {
  if (topology.schemaVersion !== 1) fail(`Knowledge Health cross-domain topology must use schema v1, got '${topology.schemaVersion}'.`);
  if (topology.crossDomainProcessCount > health.totalProcesses) fail('Cross-domain process count cannot exceed total process count.');
  if (topology.crossDomainStepCount > health.totalSteps) fail('Cross-domain step count cannot exceed total process steps.');
  if (topology.boundaryCount > topology.crossDomainStepCount) fail('Cross-domain boundary count cannot exceed cross-domain step count.');
  if (topology.participatingDomainCount < (topology.crossDomainStepCount > 0 ? 2 : 0)) fail('A non-empty cross-domain topology must contain at least two participating Domains.');
  if (topology.referenceLink !== '/12-reference/cross-domain-process-topology.html') fail(`Unexpected Cross-Domain Process Topology reference link '${topology.referenceLink}'.`);
}

const emptyTotals = {
  processes: 0,
  steps: 0,
  capabilityMappedSteps: 0,
  capabilityGapSteps: 0,
  evidenceVerifiedSteps: 0,
  runtimeVerifiedSteps: 0,
  criticalSteps: 0,
  criticalSourceVerified: 0,
  criticalRuntimeVerified: 0,
  debtItems: 0,
  highDebtItems: 0,
  mediumDebtItems: 0,
  lowDebtItems: 0,
};
const domainTotals = Object.values(health.domains).reduce((totals, domain) => {
  for (const key of Object.keys(emptyTotals)) totals[key] += domain[key];
  return totals;
}, { ...emptyTotals });

for (const [key, expected] of [
  ['processes', health.totalProcesses],
  ['steps', health.totalSteps],
  ['capabilityMappedSteps', health.capabilityMappedSteps],
  ['capabilityGapSteps', health.capabilityGapSteps],
  ['evidenceVerifiedSteps', health.evidenceVerifiedSteps],
  ['runtimeVerifiedSteps', health.runtimeVerifiedSteps],
  ['criticalSteps', health.criticalSteps],
  ['criticalSourceVerified', health.criticalSourceVerified],
  ['criticalRuntimeVerified', health.criticalRuntimeVerified],
  ['debtItems', health.debtItems],
  ['highDebtItems', health.highDebtItems],
  ['mediumDebtItems', health.mediumDebtItems],
  ['lowDebtItems', health.lowDebtItems],
]) {
  if (domainTotals[key] !== expected) fail(`Domain health sum mismatch for ${key}: domains=${domainTotals[key]}, total=${expected}.`);
}

const systemStatus = fs.readFileSync(path.join(here, 'system-status.mjs'), 'utf8');
if (!systemStatus.includes("import { buildKnowledgeHealth } from './knowledge-health.mjs';")) fail('System Status must consume the canonical Knowledge Health builder.');
if (!systemStatus.includes('knowledgeHealth,')) fail('System Status snapshot must expose knowledgeHealth.');

const themeIndex = fs.readFileSync(path.join(here, 'theme', 'index.mjs'), 'utf8');
if (!themeIndex.includes("app.component('KnowledgeHealth', KnowledgeHealth)")) fail('VitePress theme must register KnowledgeHealth component.');

const knowledgeHealthComponent = fs.readFileSync(path.join(here, 'theme', 'KnowledgeHealth.vue'), 'utf8');
if (!knowledgeHealthComponent.includes('health.value?.crossDomainTopology')) fail('Knowledge Health UI must consume Cross-Domain Process Topology summary.');
if (!knowledgeHealthComponent.includes('topology.referenceLink')) fail('Knowledge Health UI must link Cross-Domain Process Topology reference.');

const home = fs.readFileSync(path.join(repoRoot, 'docs', 'index.md'), 'utf8');
const localizedHome = fs.readFileSync(path.join(here, 'theme', 'LocalizedHome.vue'), 'utf8');
if (!home.includes('<LocalizedHome />')) fail('Documentation home must render the unified LocalizedHome composition.');
if (!localizedHome.includes('<KnowledgeHealth />')) fail('LocalizedHome must render KnowledgeHealth.');
if (!localizedHome.includes('<SystemStatus />')) fail('LocalizedHome must render executable SystemStatus.');
if (localizedHome.indexOf('<KnowledgeHealth />') > localizedHome.indexOf('<SystemStatus />')) fail('Knowledge Health must be shown before executable System Status on the documentation home.');

if (errors.length > 0) {
  console.error(`Knowledge Health checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Knowledge Health checks passed: ${health.totalProcesses} processes, ${health.capabilityMappedSteps}/${health.totalSteps} capability-mapped, ${health.evidenceVerifiedSteps}/${health.totalSteps} evidence-verified, ${health.criticalRuntimeVerified}/${health.criticalSteps} critical runtime-backed, ${topology?.crossDomainStepCount ?? 0} cross-domain steps, ${health.debtItems} debt items.`);
