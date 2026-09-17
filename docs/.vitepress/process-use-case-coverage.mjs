import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const domainsRoot = path.join(repoRoot, 'app', 'Domains');
const exemptionsFile = path.join(here, 'process-use-case-exemptions.json');

function loadExemptions() {
  if (!fs.existsSync(exemptionsFile)) return { schema_version: 0, items: [] };
  return JSON.parse(fs.readFileSync(exemptionsFile, 'utf8'));
}

function discoverUseCases() {
  const rows = [];
  if (!fs.existsSync(domainsRoot)) return rows;

  for (const dirent of fs.readdirSync(domainsRoot, { withFileTypes: true })) {
    if (!dirent.isDirectory()) continue;
    const domainRoot = path.join(domainsRoot, dirent.name);
    if (!fs.existsSync(path.join(domainRoot, 'module.php'))) continue;

    const useCaseRoot = path.join(domainRoot, 'Application', 'UseCase');
    if (!fs.existsSync(useCaseRoot)) continue;
    const domain = dirent.name.toLowerCase();

    for (const file of fs.readdirSync(useCaseRoot).filter((name) => name.endsWith('.php')).sort()) {
      rows.push({
        domain,
        useCase: file.replace(/\.php$/, ''),
        source: path.relative(repoRoot, path.join(useCaseRoot, file)).split(path.sep).join('/'),
      });
    }
  }

  return rows.sort((a, b) => `${a.domain}:${a.useCase}`.localeCompare(`${b.domain}:${b.useCase}`));
}

function mappedUseCases(definitions) {
  const mappings = new Map();
  for (const process of definitions) {
    for (const step of process.steps ?? []) {
      for (const mapping of step.runtime ?? []) {
        if (mapping?.type !== 'use_case' || typeof mapping.ref !== 'string' || mapping.ref === '') continue;
        const key = `${step.domain ?? process.domain}:${mapping.ref}`;
        const refs = mappings.get(key) ?? [];
        refs.push({ processId: process.id, stepId: step.id });
        mappings.set(key, refs);
      }
    }
  }
  return mappings;
}

export function buildProcessUseCaseCoverage(definitions = loadProcessDefinitions()) {
  const useCases = discoverUseCases();
  const exemptions = loadExemptions();
  const mappings = mappedUseCases(definitions);
  const exemptionByKey = new Map((exemptions.items ?? []).map((item) => [`${item.domain}:${item.use_case}`, item]));
  const domains = {};
  const rows = [];

  for (const useCase of useCases) {
    const key = `${useCase.domain}:${useCase.useCase}`;
    const processRefs = mappings.get(key) ?? [];
    const exemption = exemptionByKey.get(key) ?? null;
    const status = processRefs.length > 0 ? 'mapped' : exemption ? 'exempt' : 'uncovered';
    const row = { ...useCase, status, processRefs, exemption };
    rows.push(row);

    domains[useCase.domain] ??= { total: 0, mapped: 0, exempt: 0, uncovered: 0 };
    domains[useCase.domain].total += 1;
    domains[useCase.domain][status] += 1;
  }

  const totals = rows.reduce((acc, row) => {
    acc.total += 1;
    acc[row.status] += 1;
    return acc;
  }, { total: 0, mapped: 0, exempt: 0, uncovered: 0 });

  return {
    schemaVersion: 1,
    exemptionSchemaVersion: exemptions.schema_version ?? 0,
    authority: 'current-checkout-application-use-cases-plus-process-registry',
    rows,
    domains,
    exemptions: exemptions.items ?? [],
    coverageSatisfied: totals.mapped + totals.exempt,
    ...totals,
    referenceLink: '/12-reference/process-use-case-coverage.html',
  };
}
