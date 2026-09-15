import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadRuntimeEvidence, resolveRuntimeMapping } from './process-runtime-evidence.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const processRoot = path.join(here, 'processes');

function loadProcesses() {
  if (!fs.existsSync(processRoot)) return [];
  return fs.readdirSync(processRoot)
    .filter((name) => name.endsWith('.json'))
    .sort()
    .map((name) => JSON.parse(fs.readFileSync(path.join(processRoot, name), 'utf8')))
    .sort((a, b) => a.domain.localeCompare(b.domain) || a.title.localeCompare(b.title));
}

function resolveCrossDomainContract(definition, step, catalogue) {
  const contracts = Array.isArray(step.runtime)
    ? step.runtime.filter((mapping) => mapping?.type === 'contract')
    : [];

  for (const mapping of contracts) {
    const resolution = resolveRuntimeMapping(mapping, catalogue, definition.domain);
    const evidence = resolution.evidence;
    if (
      resolution.verified
      && evidence?.domain === definition.domain
      && evidence?.role === 'requires'
      && evidence?.counterpart === step.domain
    ) {
      return { mapping, resolution };
    }
  }

  return null;
}

export function buildCrossDomainProcessTopology() {
  const definitions = loadProcesses();
  const catalogue = loadRuntimeEvidence();
  const hops = [];

  for (const definition of definitions) {
    for (const step of definition.steps ?? []) {
      const stepDomain = step.domain ?? definition.domain;
      if (stepDomain === definition.domain) continue;

      const contract = resolveCrossDomainContract(definition, step, catalogue);
      if (!contract) {
        throw new Error(
          `Cross-domain process step '${definition.id}:${step.id}' has no verified requires contract from '${definition.domain}' to '${stepDomain}'.`,
        );
      }

      const evidence = contract.resolution.evidence;
      hops.push({
        id: `${definition.id}:${step.id}`,
        processId: definition.id,
        processTitle: definition.title,
        workflow: definition.workflow,
        processDomain: definition.domain,
        stepId: step.id,
        stepLabel: step.label,
        targetDomain: stepDomain,
        capability: typeof step.capability === 'string' ? step.capability : null,
        contract: contract.mapping.ref,
        evidenceStrength: contract.resolution.strength,
        evidenceRole: evidence?.role ?? null,
      });
    }
  }

  hops.sort((a, b) =>
    a.processDomain.localeCompare(b.processDomain)
    || a.targetDomain.localeCompare(b.targetDomain)
    || a.contract.localeCompare(b.contract)
    || a.processId.localeCompare(b.processId)
    || a.stepId.localeCompare(b.stepId),
  );

  const boundaryMap = new Map();
  for (const hop of hops) {
    const key = [hop.processDomain, hop.targetDomain, hop.contract, hop.capability ?? 'gap'].join('|');
    if (!boundaryMap.has(key)) {
      boundaryMap.set(key, {
        id: key,
        fromDomain: hop.processDomain,
        toDomain: hop.targetDomain,
        contract: hop.contract,
        capability: hop.capability,
        evidenceStrength: hop.evidenceStrength,
        processIds: new Set(),
        stepIds: new Set(),
      });
    }
    const boundary = boundaryMap.get(key);
    boundary.processIds.add(hop.processId);
    boundary.stepIds.add(hop.id);
  }

  const boundaries = [...boundaryMap.values()].map((boundary) => ({
    ...boundary,
    processIds: [...boundary.processIds].sort(),
    stepIds: [...boundary.stepIds].sort(),
    processCount: boundary.processIds.size,
    stepCount: boundary.stepIds.size,
  }));

  const domains = [...new Set(hops.flatMap((hop) => [hop.processDomain, hop.targetDomain]))].sort();
  const crossDomainProcesses = [...new Set(hops.map((hop) => hop.processId))].sort();

  return {
    schemaVersion: 1,
    authority: 'process-registry+runtime-evidence',
    processCount: definitions.length,
    crossDomainProcessCount: crossDomainProcesses.length,
    crossDomainStepCount: hops.length,
    boundaryCount: boundaries.length,
    domains,
    crossDomainProcesses,
    boundaries,
    hops,
    referenceLink: '/12-reference/cross-domain-process-topology.html',
  };
}
