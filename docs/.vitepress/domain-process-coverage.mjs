import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const modulesRoot = path.join(repoRoot, 'app', 'Domains');
const exemptionFile = path.join(here, 'process-coverage-exemptions.json');
const debtFile = path.join(here, 'capability-debt.json');

function readPhpString(source, key) {
  const safeKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return source.match(new RegExp(`['\"]${safeKey}['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]`))?.[1] ?? null;
}

function loadModules() {
  const modules = new Map();
  if (!fs.existsSync(modulesRoot)) return modules;

  for (const entry of fs.readdirSync(modulesRoot, { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const modulePath = path.join(modulesRoot, entry.name, 'module.php');
    if (!fs.existsSync(modulePath)) continue;
    const source = fs.readFileSync(modulePath, 'utf8');
    const id = readPhpString(source, 'id');
    if (!id) continue;
    modules.set(id, {
      id,
      name: readPhpString(source, 'name') ?? entry.name,
      version: readPhpString(source, 'version') ?? 'unknown',
      source: path.relative(repoRoot, modulePath).replaceAll('\\', '/'),
    });
  }
  return modules;
}

function loadJson(file, fallback) {
  if (!fs.existsSync(file)) return fallback;
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

export function buildDomainProcessCoverage() {
  const modules = loadModules();
  const processes = loadProcessDefinitions();
  const exemptions = loadJson(exemptionFile, { schema_version: 0, items: [] });
  const debt = loadJson(debtFile, { schema_version: 0, items: [] });

  const processesByDomain = new Map();
  for (const definition of processes) {
    const list = processesByDomain.get(definition.domain) ?? [];
    list.push(definition);
    processesByDomain.set(definition.domain, list);
  }

  const debtByDomain = new Map();
  for (const item of debt.items ?? []) {
    debtByDomain.set(item.owner_domain, (debtByDomain.get(item.owner_domain) ?? 0) + 1);
  }

  const exemptionByDomain = new Map();
  for (const item of exemptions.items ?? []) {
    if (item?.domain) exemptionByDomain.set(item.domain, item);
  }

  const domains = {};
  let coveredDomains = 0;
  let exemptDomains = 0;
  let missingDomains = 0;

  for (const [id, module] of [...modules.entries()].sort(([a], [b]) => a.localeCompare(b))) {
    const definitions = processesByDomain.get(id) ?? [];
    const exemption = exemptionByDomain.get(id) ?? null;
    const steps = definitions.flatMap((definition) => definition.steps ?? []);
    const mapped = steps.filter((step) => typeof step.capability === 'string' && step.capability !== '').length;
    const gaps = steps.filter((step) => step.capability === null && typeof step.capability_gap === 'string').length;
    let status = 'missing';
    if (definitions.length > 0) {
      status = 'covered';
      coveredDomains += 1;
    } else if (exemption) {
      status = 'exempt';
      exemptDomains += 1;
    } else {
      missingDomains += 1;
    }

    domains[id] = {
      ...module,
      status,
      processCount: definitions.length,
      processIds: definitions.map((definition) => definition.id).sort(),
      stepCount: steps.length,
      capabilityMappedSteps: mapped,
      capabilityGapSteps: gaps,
      debtItems: debtByDomain.get(id) ?? 0,
      exemption,
    };
  }

  const unknownProcessDomains = [...processesByDomain.keys()].filter((domain) => !modules.has(domain)).sort();
  const unknownExemptionDomains = [...exemptionByDomain.keys()].filter((domain) => !modules.has(domain)).sort();
  const staleExemptionDomains = [...exemptionByDomain.keys()]
    .filter((domain) => modules.has(domain) && (processesByDomain.get(domain) ?? []).length > 0)
    .sort();

  return {
    authority: 'module-manifests+process-registry',
    exemptionSchemaVersion: exemptions.schema_version ?? 0,
    installableDomains: modules.size,
    coveredDomains,
    exemptDomains,
    missingDomains,
    coverageSatisfiedDomains: coveredDomains + exemptDomains,
    domains,
    unknownProcessDomains,
    unknownExemptionDomains,
    staleExemptionDomains,
    referenceLink: '/12-reference/domain-process-coverage.html',
  };
}
