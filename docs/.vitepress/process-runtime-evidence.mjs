import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const repoRoot = path.resolve(docsRoot, '..');
const catalogueScript = path.join(here, 'generate-runtime-evidence.php');

const RUNTIME_STRENGTH = 'runtime';
const SOURCE_STRENGTH = 'source';

export function loadRuntimeEvidence() {
  let raw;
  try {
    raw = execFileSync('php', [catalogueScript], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (error) {
    const stderr = error?.stderr ? String(error.stderr) : String(error);
    throw new Error(`Cannot build runtime evidence catalogue: ${stderr.trim()}`);
  }

  let catalogue;
  try {
    catalogue = JSON.parse(raw);
  } catch (error) {
    throw new Error(`Runtime evidence catalogue is invalid JSON: ${error instanceof Error ? error.message : String(error)}`);
  }

  if (catalogue.schema_version !== 1 || !Array.isArray(catalogue.entries)) {
    throw new Error('Runtime evidence catalogue must use schema_version 1 and contain entries.');
  }

  return catalogue;
}

export function resolveRuntimeMapping(mapping, catalogue, processDomain = '') {
  if (!mapping || typeof mapping !== 'object') {
    return { verified: false, strength: null, reason: 'invalid mapping' };
  }

  if (mapping.type === 'source') {
    if (!mapping.path || typeof mapping.path !== 'string') {
      return { verified: false, strength: null, reason: 'source mapping requires path' };
    }

    const absolute = path.join(repoRoot, mapping.path);
    if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) {
      return { verified: false, strength: null, reason: `source path does not exist: ${mapping.path}` };
    }

    if (mapping.symbol) {
      const source = fs.readFileSync(absolute, 'utf8');
      if (!source.includes(mapping.symbol)) {
        return { verified: false, strength: null, reason: `source does not contain symbol '${mapping.symbol}'` };
      }
    }

    return {
      verified: true,
      strength: SOURCE_STRENGTH,
      evidence: {
        type: 'source',
        ref: mapping.path,
        domain: processDomain,
        source: mapping.path,
        strength: SOURCE_STRENGTH,
      },
    };
  }

  if (!mapping.type || !mapping.ref || typeof mapping.ref !== 'string') {
    return { verified: false, strength: null, reason: `${mapping.type ?? 'unknown'} mapping requires ref` };
  }

  const matches = catalogue.entries.filter((entry) => entry.type === mapping.type && entry.ref === mapping.ref);
  if (matches.length === 0) {
    return { verified: false, strength: null, reason: `missing ${mapping.type} '${mapping.ref}'` };
  }

  const preferred = matches.find((entry) =>
    entry.domain === processDomain || entry.counterpart === processDomain,
  ) ?? matches[0];

  return {
    verified: true,
    strength: preferred.strength === RUNTIME_STRENGTH ? RUNTIME_STRENGTH : SOURCE_STRENGTH,
    evidence: preferred,
  };
}

export function processVerification(definition, catalogue) {
  const steps = Array.isArray(definition.steps) ? definition.steps : [];
  const criticalSteps = steps.filter((step) => step.critical === true);

  const stepEvidence = steps.map((step) => {
    const mappings = Array.isArray(step.runtime) ? step.runtime : [];
    const resolutions = mappings.map((mapping) => resolveRuntimeMapping(mapping, catalogue, definition.domain));
    return {
      id: step.id,
      mappings: mappings.length,
      verifiedMappings: resolutions.filter((resolution) => resolution.verified).length,
      sourceVerified: resolutions.some((resolution) => resolution.verified),
      runtimeVerified: resolutions.some((resolution) => resolution.verified && resolution.strength === RUNTIME_STRENGTH),
      resolutions,
    };
  });

  const byId = new Map(stepEvidence.map((entry) => [entry.id, entry]));
  const criticalSourceVerified = criticalSteps.filter((step) => byId.get(step.id)?.sourceVerified === true).length;
  const criticalRuntimeVerified = criticalSteps.filter((step) => byId.get(step.id)?.runtimeVerified === true).length;

  let level = 'documented';
  if (criticalSteps.length > 0 && criticalSourceVerified === criticalSteps.length) {
    level = 'source-verified';
  }
  if (criticalSteps.length > 0 && criticalRuntimeVerified === criticalSteps.length) {
    level = 'runtime-verified';
  }

  return {
    level,
    steps: steps.length,
    critical: criticalSteps.length,
    mappedSteps: stepEvidence.filter((entry) => entry.mappings > 0).length,
    verifiedSteps: stepEvidence.filter((entry) => entry.sourceVerified).length,
    runtimeVerifiedSteps: stepEvidence.filter((entry) => entry.runtimeVerified).length,
    criticalSourceVerified,
    criticalRuntimeVerified,
    stepEvidence,
  };
}

export const PROCESS_VERIFICATION_LEVELS = Object.freeze([
  'documented',
  'source-verified',
  'runtime-verified',
]);
