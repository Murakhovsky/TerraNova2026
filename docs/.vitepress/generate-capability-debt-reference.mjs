import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { loadRuntimeEvidence, processVerification } from './process-runtime-evidence.mjs';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const debtFile = path.join(here, 'capability-debt.json');
const output = path.join(docsRoot, '12-reference', 'capability-debt.md');
const checkOnly = process.argv.includes('--check');
const catalogue = loadRuntimeEvidence();

function loadProcesses() {
  return new Map(loadProcessDefinitions().map((definition) => [definition.id, definition]));
}

function loadDebt() {
  return JSON.parse(fs.readFileSync(debtFile, 'utf8'));
}

function severityRank(value) {
  return ({ high: 0, medium: 1, low: 2 })[value] ?? 99;
}

function stepEvidenceLevel(definition, stepId) {
  const verification = processVerification(definition, catalogue);
  const evidence = verification.stepEvidence.find((entry) => entry.id === stepId);
  if (evidence?.runtimeVerified) return 'runtime';
  if (evidence?.sourceVerified) return 'source';
  return 'documented';
}

function render(processes, registry) {
  const items = [...(registry.items ?? [])].sort((a, b) =>
    severityRank(a.severity) - severityRank(b.severity)
    || a.owner_domain.localeCompare(b.owner_domain)
    || a.process_id.localeCompare(b.process_id)
    || a.step_id.localeCompare(b.step_id),
  );

  const domainSummary = new Map();
  for (const item of items) {
    if (!domainSummary.has(item.owner_domain)) {
      domainSummary.set(item.owner_domain, { open: 0, high: 0, medium: 0, low: 0 });
    }
    const summary = domainSummary.get(item.owner_domain);
    summary.open += 1;
    summary[item.severity] += 1;
  }

  const lines = [
    '---',
    'title: Capability Debt Backlog',
    'description: Generated backlog of unresolved Domain capability vocabulary gaps linked to canonical COS business-process steps.',
    'status: generated',
    'updated: 2026-09-15',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Capability Debt Backlog',
    '',
    'Generated from `docs/.vitepress/capability-debt.json`, canonical `resources/processes/*.json` gap steps and current-checkout module capability authority. Do not edit this page manually.',
    '',
    'Capability debt means the business step is real but the owning Domain does not yet expose a sufficiently semantic discoverable capability. It does **not** mean the runtime implementation is absent.',
    '',
    '## Summary',
    '',
    `- **Open debt items:** ${items.length}`,
    `- **High severity:** ${items.filter((item) => item.severity === 'high').length}`,
    `- **Medium severity:** ${items.filter((item) => item.severity === 'medium').length}`,
    `- **Affected Domains:** ${domainSummary.size}`,
    '',
    '| Domain | Open | High | Medium | Low |',
    '| --- | ---: | ---: | ---: | ---: |',
  ];

  for (const domain of [...domainSummary.keys()].sort()) {
    const summary = domainSummary.get(domain);
    lines.push(`| \`${domain}\` | ${summary.open} | ${summary.high} | ${summary.medium} | ${summary.low} |`);
  }

  lines.push(
    '',
    '## Prioritized backlog',
    '',
    '| Severity | Domain | Process / step | Runtime evidence | Target capability | Resolution |',
    '| --- | --- | --- | --- | --- | --- |',
  );

  for (const item of items) {
    const definition = processes.get(item.process_id);
    const step = (definition?.steps ?? []).find((candidate) => candidate.id === item.step_id);
    const workflow = definition ? `../${definition.workflow}` : '#';
    const title = definition?.title ?? item.process_id;
    const label = step?.label ?? item.step_id;
    const evidence = definition ? stepEvidenceLevel(definition, item.step_id) : 'documented';
    lines.push(`| \`${item.severity}\` | \`${item.owner_domain}\` | [${title}](${workflow}) · \`${item.step_id}\` · ${label} | \`${evidence}\` | \`${item.target_capability}\` | \`${item.resolution}\` |`);
  }

  lines.push(
    '',
    '## Resolution contract',
    '',
    'A debt item is resolved only when all of the following become true:',
    '',
    '1. The owning Domain declares the target capability in its canonical module `contributions.capabilities`.',
    '2. The matching Process Registry step replaces `capability: null` + `capability_gap` with that declared capability.',
    '3. The matching item is removed from `capability-debt.json`.',
    '4. Documentation generation and checks pass from the same checkout.',
    '',
    '`check-capability-debt.mjs` enforces the 1:1 relation between Process Registry gaps and debt items. It also rejects stale debt whose target capability already exists, wrong Domain ownership, invalid severity, or target names outside the owning Domain namespace.',
    '',
    '## Severity policy',
    '',
    '- `high` — the capability gap is attached to a critical process step.',
    '- `medium` — the gap is attached to a non-critical but canonical process step.',
    '- `low` — reserved for future non-canonical/optional debt classes; current workflow debt does not use it.',
    '',
    'Severity describes architecture-model debt, not operational incident severity.',
    '',
    '## Authority and limitations',
    '',
    '- Process Registry owns the fact that a capability gap exists.',
    '- Capability Debt Registry owns the remediation metadata for that gap.',
    '- Domain module manifests remain the authority for capabilities that actually exist.',
    '- Runtime Evidence Resolver remains the authority for source/runtime verification.',
    '- Generated Markdown is a projection only and is never used as executable authority.',
    '- Resolving debt may require a Domain patch release; this documentation layer does not silently mutate Domain manifests.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(loadProcesses(), loadDebt());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated Capability Debt reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  if (fs.readFileSync(output, 'utf8') !== rendered) {
    console.error('Generated Capability Debt reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Capability Debt reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
