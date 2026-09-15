import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildKnowledgeHealth } from './knowledge-health.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const errors = [];
const health = buildKnowledgeHealth();

function fail(message) {
  errors.push(message);
}

if (health.authority !== 'structured-current-checkout') fail(`Unexpected knowledge-health authority '${health.authority}'.`);
if (health.totalProcesses <= 0) fail('Knowledge Health must contain at least one canonical process.');
if (health.totalSteps <= 0) fail('Knowledge Health must contain canonical process steps.');
if (health.capabilityMappedSteps + health.capabilityGapSteps !== health.totalSteps) {
  fail('Capability mapped steps + capability gaps must equal total process steps.');
}
if (health.debtItems !== health.capabilityGapSteps) {
  fail(`Capability debt must track every gap exactly: debt=${health.debtItems}, gaps=${health.capabilityGapSteps}.`);
}
if (health.evidenceVerifiedSteps > health.totalSteps || health.runtimeVerifiedSteps > health.totalSteps) {
  fail('Evidence coverage cannot exceed total process steps.');
}
if (health.criticalSourceVerified > health.criticalSteps || health.criticalRuntimeVerified > health.criticalSteps) {
  fail('Critical verification cannot exceed critical step count.');
}
if (!health.processSchemaVersions.includes(4)) fail('Knowledge Health must expose current Process Registry schema v4.');
if (health.debtSchemaVersion !== 1) fail(`Knowledge Health must expose Capability Debt schema v1, got '${health.debtSchemaVersion}'.`);

const domainTotals = Object.values(health.domains).reduce((totals, domain) => ({
  processes: totals.processes + domain.processes,
  steps: totals.steps + domain.steps,
  capabilityMappedSteps: totals.capabilityMappedSteps + domain.capabilityMappedSteps,
  capabilityGapSteps: totals.capabilityGapSteps + domain.capabilityGapSteps,
  evidenceVerifiedSteps: totals.evidenceVerifiedSteps + domain.evidenceVerifiedSteps,
  runtimeVerifiedSteps: totals.runtimeVerifiedSteps + domain.runtimeVerifiedSteps,
  criticalSteps: totals.criticalSteps + domain.criticalSteps,
  criticalSourceVerified: totals.criticalSourceVerified + domain.criticalSourceVerified,
  criticalRuntimeVerified: totals.criticalRuntimeVerified + domain.criticalRuntimeVerified,
  debtItems: totals.debtItems + domain.debtItems,
  highDebtItems: totals.highDebtItems + domain.highDebtItems,
  mediumDebtItems: totals.mediumDebtItems + domain.mediumDebtItems,
  lowDebtItems: totals.lowDebtItems + domain.lowDebtItems,
}), {
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
});

const comparisons = [
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
];
for (const [key, expected] of comparisons) {
  if (domainTotals[key] !== expected) fail(`Domain health sum mismatch for ${key}: domains=${domainTotals[key]}, total=${expected}.`);
}

const systemStatus = fs.readFileSync(path.join(here, 'system-status.mjs'), 'utf8');
if (!systemStatus.includes("import { buildKnowledgeHealth } from './knowledge-health.mjs';")) {
  fail('System Status must consume the canonical Knowledge Health builder.');
}
if (!systemStatus.includes('knowledgeHealth,')) fail('System Status snapshot must expose knowledgeHealth.');

const themeIndex = fs.readFileSync(path.join(here, 'theme', 'index.mjs'), 'utf8');
if (!themeIndex.includes("app.component('KnowledgeHealth', KnowledgeHealth)")) {
  fail('VitePress theme must register KnowledgeHealth component.');
}

const home = fs.readFileSync(path.join(repoRoot, 'docs', 'index.md'), 'utf8');
if (!home.includes('<KnowledgeHealth />')) fail('Documentation home must render KnowledgeHealth.');
if (home.indexOf('<KnowledgeHealth />') > home.indexOf('<SystemStatus />')) {
  fail('Knowledge Health must be shown before executable System Status on the documentation home.');
}

if (errors.length > 0) {
  console.error(`Knowledge Health checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Knowledge Health checks passed: ${health.totalProcesses} processes, ${health.capabilityMappedSteps}/${health.totalSteps} capability-mapped, ${health.evidenceVerifiedSteps}/${health.totalSteps} evidence-verified, ${health.criticalRuntimeVerified}/${health.criticalSteps} critical runtime-backed, ${health.debtItems} debt items.`);
