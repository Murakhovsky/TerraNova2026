import process from 'node:process';
import { buildProcessUseCaseCoverage } from './process-use-case-coverage.mjs';

const coverage = buildProcessUseCaseCoverage();
const errors = [];
const fail = (message) => errors.push(message);

if (coverage.schemaVersion !== 1) fail(`Unexpected Process Use-Case Coverage schema '${coverage.schemaVersion}'.`);
if (coverage.exemptionSchemaVersion !== 1) fail(`Process Use-Case exemptions must use schema v1, got '${coverage.exemptionSchemaVersion}'.`);
if (coverage.total <= 0) fail('Process Use-Case Coverage must discover Application Use Cases in installable Domains.');
if (coverage.mapped + coverage.exempt + coverage.uncovered !== coverage.total) fail('Mapped + exempt + uncovered must equal total Application Use Cases.');
if (coverage.coverageSatisfied !== coverage.mapped + coverage.exempt) fail('Coverage satisfied count must equal mapped + exempt.');

const known = new Set(coverage.rows.map((row) => `${row.domain}:${row.useCase}`));
const exemptionKeys = new Set();
for (const item of coverage.exemptions) {
  const key = `${item.domain}:${item.use_case}`;
  if (!item.domain || !item.use_case || !item.owner || !item.reason) fail(`Malformed process use-case exemption '${key}'.`);
  if (exemptionKeys.has(key)) fail(`Duplicate process use-case exemption '${key}'.`);
  exemptionKeys.add(key);
  if (!known.has(key)) fail(`Stale process use-case exemption '${key}' does not resolve to a current installable Application Use Case.`);
  const row = coverage.rows.find((candidate) => `${candidate.domain}:${candidate.useCase}` === key);
  if (row?.processRefs?.length > 0) fail(`Stale process use-case exemption '${key}' is already mapped by Process Registry.`);
}

for (const row of coverage.rows.filter((candidate) => candidate.status === 'uncovered')) {
  fail(`Application Use Case '${row.domain}:${row.useCase}' is not represented in Process Registry and has no explicit exemption.`);
}

if (errors.length > 0) {
  console.error(`Process Use-Case Coverage checks failed (${errors.length}):`);
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Process Use-Case Coverage checks passed: ${coverage.coverageSatisfied}/${coverage.total} covered, ${coverage.mapped} mapped, ${coverage.exempt} exempt, ${coverage.uncovered} uncovered.`);
