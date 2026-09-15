import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadRuntimeEvidence, processVerification } from './process-runtime-evidence.mjs';
import { buildCrossDomainProcessTopology } from './cross-domain-process-topology.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const processRoot = path.join(here, 'processes');
const debtFile = path.join(here, 'capability-debt.json');

function emptyDomainHealth() {
  return {
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
}

function loadProcesses() {
  if (!fs.existsSync(processRoot)) return [];
  return fs.readdirSync(processRoot)
    .filter((name) => name.endsWith('.json'))
    .sort()
    .map((name) => JSON.parse(fs.readFileSync(path.join(processRoot, name), 'utf8')));
}

function loadDebt() {
  if (!fs.existsSync(debtFile)) return { schema_version: 0, items: [] };
  return JSON.parse(fs.readFileSync(debtFile, 'utf8'));
}

export function buildKnowledgeHealth() {
  const definitions = loadProcesses();
  const debt = loadDebt();
  const catalogue = loadRuntimeEvidence();
  const crossDomain = buildCrossDomainProcessTopology(definitions, catalogue);
  const domains = {};

  const totals = {
    totalProcesses: definitions.length,
    totalSteps: 0,
    capabilityMappedSteps: 0,
    capabilityGapSteps: 0,
    evidenceVerifiedSteps: 0,
    runtimeVerifiedSteps: 0,
    criticalSteps: 0,
    criticalSourceVerified: 0,
    criticalRuntimeVerified: 0,
  };

  for (const definition of definitions) {
    const domain = definition.domain;
    domains[domain] ??= emptyDomainHealth();
    const stats = domains[domain];
    const steps = Array.isArray(definition.steps) ? definition.steps : [];
    const verification = processVerification(definition, catalogue);
    const capabilityMapped = steps.filter((step) => typeof step.capability === 'string' && step.capability !== '').length;
    const capabilityGaps = steps.filter((step) => step.capability === null && typeof step.capability_gap === 'string').length;

    stats.processes += 1;
    stats.steps += steps.length;
    stats.capabilityMappedSteps += capabilityMapped;
    stats.capabilityGapSteps += capabilityGaps;
    stats.evidenceVerifiedSteps += verification.verifiedSteps;
    stats.runtimeVerifiedSteps += verification.runtimeVerifiedSteps;
    stats.criticalSteps += verification.critical;
    stats.criticalSourceVerified += verification.criticalSourceVerified;
    stats.criticalRuntimeVerified += verification.criticalRuntimeVerified;

    totals.totalSteps += steps.length;
    totals.capabilityMappedSteps += capabilityMapped;
    totals.capabilityGapSteps += capabilityGaps;
    totals.evidenceVerifiedSteps += verification.verifiedSteps;
    totals.runtimeVerifiedSteps += verification.runtimeVerifiedSteps;
    totals.criticalSteps += verification.critical;
    totals.criticalSourceVerified += verification.criticalSourceVerified;
    totals.criticalRuntimeVerified += verification.criticalRuntimeVerified;
  }

  let highDebtItems = 0;
  let mediumDebtItems = 0;
  let lowDebtItems = 0;
  for (const item of debt.items ?? []) {
    const domain = item.owner_domain;
    domains[domain] ??= emptyDomainHealth();
    domains[domain].debtItems += 1;
    if (item.severity === 'high') {
      domains[domain].highDebtItems += 1;
      highDebtItems += 1;
    } else if (item.severity === 'medium') {
      domains[domain].mediumDebtItems += 1;
      mediumDebtItems += 1;
    } else if (item.severity === 'low') {
      domains[domain].lowDebtItems += 1;
      lowDebtItems += 1;
    }
  }

  const processSchemaVersions = [...new Set(definitions.map((definition) => definition.schema_version))].sort();

  return {
    authority: 'structured-current-checkout',
    processSchemaVersions,
    debtSchemaVersion: debt.schema_version ?? 0,
    crossDomainTopology: {
      schemaVersion: crossDomain.schemaVersion,
      crossDomainProcessCount: crossDomain.crossDomainProcessCount,
      crossDomainStepCount: crossDomain.crossDomainStepCount,
      boundaryCount: crossDomain.boundaryCount,
      participatingDomainCount: crossDomain.domains.length,
      referenceLink: crossDomain.referenceLink,
    },
    ...totals,
    debtItems: (debt.items ?? []).length,
    highDebtItems,
    mediumDebtItems,
    lowDebtItems,
    domains,
    processReferenceLink: '/12-reference/business-processes.html',
    debtReferenceLink: '/12-reference/capability-debt.html',
  };
}
