import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildDomainProcessCoverage } from './domain-process-coverage.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const exemptionsFile = path.join(here, 'process-coverage-exemptions.json');
const referenceIndex = path.join(repoRoot, 'docs', '12-reference', 'README.md');
const errors = [];
const coverage = buildDomainProcessCoverage();

function fail(message) {
  errors.push(message);
}

if (coverage.authority !== 'module-manifests+process-registry') {
  fail(`Unexpected Domain process coverage authority '${coverage.authority}'.`);
}
if (coverage.exemptionSchemaVersion !== 1) {
  fail(`Process coverage exemptions must use schema v1, got '${coverage.exemptionSchemaVersion}'.`);
}
if (coverage.installableDomains === 0) fail('No installable Domain modules were discovered.');
if (coverage.coverageSatisfiedDomains !== coverage.installableDomains) {
  fail(`Every installable Domain must be covered or exempt: ${coverage.coverageSatisfiedDomains}/${coverage.installableDomains} satisfied.`);
}
if (coverage.missingDomains !== 0) {
  const missing = Object.values(coverage.domains).filter((domain) => domain.status === 'missing').map((domain) => domain.id).join(', ');
  fail(`Installable Domains without canonical process or exemption: ${missing || 'unknown'}.`);
}
if (coverage.unknownProcessDomains.length > 0) {
  fail(`Canonical processes reference non-installable Domains: ${coverage.unknownProcessDomains.join(', ')}.`);
}
if (coverage.unknownExemptionDomains.length > 0) {
  fail(`Coverage exemptions reference non-installable Domains: ${coverage.unknownExemptionDomains.join(', ')}.`);
}
if (coverage.staleExemptionDomains.length > 0) {
  fail(`Coverage exemptions are stale because canonical processes now exist: ${coverage.staleExemptionDomains.join(', ')}.`);
}

const exemptions = JSON.parse(fs.readFileSync(exemptionsFile, 'utf8'));
const seen = new Set();
for (const item of exemptions.items ?? []) {
  if (!item || typeof item !== 'object') {
    fail('Process coverage exemptions contain a non-object item.');
    continue;
  }
  for (const key of ['domain', 'reason', 'owner']) {
    if (!item[key] || typeof item[key] !== 'string') fail(`Coverage exemption '${item.domain ?? 'unknown'}' requires string '${key}'.`);
  }
  if (seen.has(item.domain)) fail(`Duplicate process coverage exemption for '${item.domain}'.`);
  seen.add(item.domain);
  if ((item.reason ?? '').trim().length < 20) fail(`Coverage exemption '${item.domain}' reason must explain the architecture exception.`);
}

for (const domain of Object.values(coverage.domains)) {
  if (domain.status === 'covered' && domain.processCount < 1) fail(`Covered Domain '${domain.id}' has no process.`);
  if (domain.status === 'exempt' && !domain.exemption) fail(`Exempt Domain '${domain.id}' has no exemption metadata.`);
  if (domain.status === 'missing') fail(`Domain '${domain.id}' is missing process coverage.`);
}

if (!fs.existsSync(referenceIndex)) {
  fail('Reference Index is missing.');
} else {
  const index = fs.readFileSync(referenceIndex, 'utf8');
  if (!index.includes('Process Coverage Exemptions schema: **v1**.')) {
    fail('Reference Index must declare Process Coverage Exemptions schema v1.');
  }
  if (!index.includes('[Domain Process Coverage](domain-process-coverage.md)')) {
    fail('Reference Index must link generated Domain Process Coverage.');
  }
}

if (errors.length > 0) {
  console.error(`Domain Process Coverage checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Domain Process Coverage checks passed: ${coverage.coveredDomains}/${coverage.installableDomains} covered, ${coverage.exemptDomains} exempt, ${coverage.missingDomains} missing.`);
