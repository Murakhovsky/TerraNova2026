import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildKnowledgeHealth } from './knowledge-health.mjs';
import { buildDomainProcessCoverage } from './domain-process-coverage.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '..', '..');
const docsRoot = join(repoRoot, 'docs');
const domainsRoot = join(repoRoot, 'app', 'Domains');

function readPhpString(source, key) {
  const safeKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return source.match(new RegExp(`['\"]${safeKey}['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]`))?.[1] ?? null;
}

function readKernelVersion() {
  const path = join(repoRoot, 'app', 'Kernel', 'Module', 'KernelVersion.php');
  if (!existsSync(path)) return 'unknown';

  return readFileSync(path, 'utf8').match(/public\s+const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]/)?.[1] ?? 'unknown';
}

function countCapabilities(source) {
  const body = source.match(/['\"]capabilities['\"]\s*=>\s*\[([\s\S]*?)\]\s*,/)?.[1] ?? '';
  return [...body.matchAll(/['\"]([^'\"]+)['\"]/g)].length;
}

function canonicalDomainLink(id) {
  const path = join(docsRoot, '04-domains', id, 'overview.md');
  return existsSync(path) ? `/04-domains/${id}/overview.html` : null;
}

function humanize(value) {
  return value.replace(/([a-z0-9])([A-Z])/g, '$1 $2');
}

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

export function buildSystemStatus() {
  const modules = [];
  const supportingAreas = [];
  const knowledgeHealth = buildKnowledgeHealth();
  const processCoverage = buildDomainProcessCoverage();

  if (existsSync(domainsRoot)) {
    for (const entry of readdirSync(domainsRoot, { withFileTypes: true })) {
      if (!entry.isDirectory()) continue;

      const modulePath = join(domainsRoot, entry.name, 'module.php');
      if (!existsSync(modulePath)) {
        supportingAreas.push({
          id: entry.name.toLowerCase(),
          name: humanize(entry.name),
          link: '/04-domains/supporting-domains.html',
        });
        continue;
      }

      const source = readFileSync(modulePath, 'utf8');
      const id = readPhpString(source, 'id') ?? entry.name.toLowerCase();
      const link = canonicalDomainLink(id);

      modules.push({
        id,
        name: readPhpString(source, 'name') ?? humanize(entry.name),
        version: readPhpString(source, 'version') ?? 'unknown',
        schemaVersion: readPhpString(source, 'schema_version') ?? 'unknown',
        kernelConstraint: readPhpString(source, 'kernel_constraint') ?? 'unknown',
        description: readPhpString(source, 'description') ?? 'Installable COS Domain.',
        capabilityCount: countCapabilities(source),
        documented: Boolean(link),
        link,
        health: knowledgeHealth.domains[id] ?? emptyDomainHealth(),
        processCoverage: processCoverage.domains[id] ?? null,
      });
    }
  }

  modules.sort((a, b) => a.name.localeCompare(b.name, 'en'));
  supportingAreas.sort((a, b) => a.name.localeCompare(b.name, 'en'));

  return {
    authority: 'runtime+structured-docs',
    branch: 'main',
    kernel: {
      name: 'Kernel',
      version: readKernelVersion(),
      description: 'Generic execution platform for actions, policy, approvals, queue, events, modules, observability, transactions and governed LLM runtime.',
      link: '/03-architecture/kernel-overview.html',
    },
    modules,
    supportingAreas,
    knowledgeHealth,
    processCoverage,
    documentedModules: modules.filter((module) => module.documented).length,
    totalModules: modules.length,
    referenceKinds: ['modules', 'capabilities', 'processes', 'domain process coverage', 'capability debt', 'runtime evidence', 'permissions', 'events', 'application/routes', 'commands'],
    referenceLink: '/12-reference/README.html',
    auditLink: '/01-product/documentation-sync.html',
  };
}
