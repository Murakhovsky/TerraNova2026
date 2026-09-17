import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildProcessUseCaseCoverage } from './process-use-case-coverage.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const output = path.join(docsRoot, '12-reference', 'process-use-case-coverage.md');
const checkOnly = process.argv.includes('--check');

function processRefs(row) {
  if (row.processRefs.length === 0) return '—';
  return row.processRefs.map((ref) => `\`${ref.processId}:${ref.stepId}\``).join('<br>');
}

function render(coverage) {
  const lines = [
    '---',
    'title: Process use-case coverage',
    'description: Generated coverage of installable Domain Application Use Cases by canonical Process Registry steps.',
    'status: generated',
    'updated: 2026-09-17',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Process use-case coverage',
    '',
    '> Authority: current-checkout `app/Domains/*/Application/UseCase/*.php`, installable module manifests, Process Registry mappings and explicit exemptions.',
    '',
    'This reference answers a different question from Domain Process Coverage: not merely whether a Domain has a process model, but whether its executable Application entry points are represented in canonical business-process semantics.',
    '',
    '## Summary',
    '',
    `- **Application Use Cases:** ${coverage.total}`,
    `- **Mapped to canonical process steps:** ${coverage.mapped}`,
    `- **Explicit exemptions:** ${coverage.exempt}`,
    `- **Uncovered:** ${coverage.uncovered}`,
    `- **Coverage satisfied:** ${coverage.coverageSatisfied}/${coverage.total}`,
    '',
    '| Domain | Use Cases | Mapped | Exempt | Uncovered |',
    '| --- | ---: | ---: | ---: | ---: |',
  ];

  for (const [domain, stats] of Object.entries(coverage.domains).sort(([a], [b]) => a.localeCompare(b))) {
    lines.push(`| \`${domain}\` | ${stats.total} | ${stats.mapped} | ${stats.exempt} | ${stats.uncovered} |`);
  }

  lines.push('', '## Application entry points', '', '| Domain | Use Case | Status | Canonical process step | Source |', '| --- | --- | --- | --- | --- |');
  for (const row of coverage.rows) {
    lines.push(`| \`${row.domain}\` | \`${row.useCase}\` | \`${row.status}\` | ${processRefs(row)} | \`${row.source}\` |`);
  }

  lines.push('', '## Explicit exemptions', '');
  if (coverage.exemptions.length === 0) {
    lines.push('No active exemptions.', '');
  } else {
    lines.push('| Domain | Use Case | Owner | Reason |', '| --- | --- | --- | --- |');
    for (const item of coverage.exemptions) {
      lines.push(`| \`${item.domain}\` | \`${item.use_case}\` | ${item.owner} | ${item.reason} |`);
    }
    lines.push('');
  }

  lines.push(
    '## Coverage contract',
    '',
    '1. Only Application Use Cases owned by installable Domains are included.',
    '2. A use case is mapped when at least one Process Registry step references it with `runtime.type = use_case`.',
    '3. An executable use case that is intentionally not a business-process step requires an explicit exemption with an owner and reason.',
    '4. Stale exemptions are invalid and fail CI.',
    '5. Coverage does not mean the use case is runtime-observed in production; runtime evidence strength remains a separate dimension.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(buildProcessUseCaseCoverage());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated Process Use-Case Coverage reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  if (fs.readFileSync(output, 'utf8') !== rendered) {
    console.error('Generated Process Use-Case Coverage reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Process Use-Case Coverage reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
