import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  loadRuntimeEvidence,
  processVerification,
  resolveRuntimeMapping,
} from './process-runtime-evidence.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const registryRoot = path.join(here, 'processes');
const output = path.join(docsRoot, '12-reference/business-processes.md');
const checkOnly = process.argv.includes('--check');
const catalogue = loadRuntimeEvidence();

function loadDefinitions() {
  return fs.readdirSync(registryRoot)
    .filter((name) => name.endsWith('.json'))
    .sort()
    .map((name) => JSON.parse(fs.readFileSync(path.join(registryRoot, name), 'utf8')))
    .sort((a, b) => a.domain.localeCompare(b.domain) || a.title.localeCompare(b.title));
}

function escapeCell(value) {
  return String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
}

function workflowLink(definition) {
  return `../${definition.workflow}`;
}

function mappingLabel(mapping, definition) {
  const resolution = resolveRuntimeMapping(mapping, catalogue, definition.domain);
  const suffix = resolution.verified ? ` [${resolution.strength}]` : ' [unresolved]';
  if (mapping.type === 'source') {
    return `source \`${mapping.path}\`${mapping.symbol ? ` · \`${mapping.symbol}\`` : ''}${suffix}`;
  }
  return `${mapping.type} \`${mapping.ref}\`${suffix}`;
}

function coverage(definition) {
  const verification = processVerification(definition, catalogue);
  const steps = definition.steps ?? [];
  return {
    ...verification,
    owned: steps.filter((step) => typeof step.owner === 'string' && step.owner !== '').length,
  };
}

function render(definitions) {
  const lines = [
    '---',
    'title: Business Process Registry',
    'description: Generated registry of canonical COS business processes, ownership and evidence-backed runtime verification.',
    'status: generated',
    'updated: 2026-09-15',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Business Process Registry',
    '',
    'Generated from `docs/.vitepress/processes/*.json` and the current-checkout runtime evidence catalogue. Do not edit this page manually.',
    '',
    'Business state and verification are separate dimensions: `as-is` / `to-be` describes the process itself; `documented` / `source-verified` / `runtime-verified` describes how strongly its critical steps are backed by current code and canonical runtime registries.',
    '',
    '## Process index',
    '',
    '| Process | Domain | Business state | Verification | Steps | Ownership | Evidence verified | Critical source | Critical runtime | Workflow |',
    '| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |',
  ];

  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | \`${definition.domain}\` | \`${definition.state}\` | \`${stats.level}\` | ${stats.steps} | ${stats.owned}/${stats.steps} | ${stats.verifiedSteps}/${stats.steps} | ${stats.criticalSourceVerified}/${stats.critical} | ${stats.criticalRuntimeVerified}/${stats.critical} | [Open workflow](${workflowLink(definition)}) |`);
  }

  lines.push('', '## Verification model', '');
  lines.push('- `documented` — registry topology exists, but at least one critical step is not backed by resolvable current-checkout evidence.');
  lines.push('- `source-verified` — every critical step has at least one mapping resolved to current source/code evidence.');
  lines.push('- `runtime-verified` — every critical step has at least one canonical runtime/contract-registry mapping. This is structural verification, not proof that a production execution trace was observed.');
  lines.push('', '| Process | Owned steps | Mapped steps | Evidence-verified steps | Runtime-backed steps | Critical source-verified | Critical runtime-verified |', '| --- | ---: | ---: | ---: | ---: | ---: | ---: |');
  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | ${stats.owned}/${stats.steps} | ${stats.mappedSteps}/${stats.steps} | ${stats.verifiedSteps}/${stats.steps} | ${stats.runtimeVerifiedSteps}/${stats.steps} | ${stats.criticalSourceVerified}/${stats.critical} | ${stats.criticalRuntimeVerified}/${stats.critical} |`);
  }

  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push('', `## ${definition.title}`, '');
    lines.push(`- **Process ID:** \`${definition.id}\``);
    lines.push(`- **Schema:** \`v${definition.schema_version}\``);
    lines.push(`- **Domain:** \`${definition.domain}\``);
    lines.push(`- **Business state:** \`${definition.state}\``);
    lines.push(`- **Derived verification:** \`${stats.level}\``);
    lines.push(`- **Trigger:** ${definition.trigger}`);
    lines.push(`- **Workflow:** [${definition.title}](${workflowLink(definition)})`);
    lines.push('', '**Outcomes**', '');
    for (const outcome of definition.outcomes) lines.push(`- ${outcome}`);
    lines.push('', '**Ownership and runtime evidence**', '');
    lines.push('| Step | Owner | Kind | Critical | Executable / evidence mapping |');
    lines.push('| --- | --- | --- | --- | --- |');
    for (const step of definition.steps) {
      const mappings = (step.runtime ?? []).map((mapping) => mappingLabel(mapping, definition)).join('<br>') || '—';
      lines.push(`| ${escapeCell(step.label)} | ${escapeCell(step.owner ?? '—')} | \`${step.kind}\` | ${step.critical === true ? 'yes' : 'no'} | ${mappings} |`);
    }
  }

  lines.push(
    '',
    '## Authority and limitations',
    '',
    '- Registry schema `v3` keeps business state (`as-is` / `to-be`) separate from derived verification.',
    '- Verification is never authored in process JSON. It is calculated from mappings resolved against `generate-runtime-evidence.php` and exact source symbols in the current checkout.',
    '- `use_case` and `command` evidence is source-backed from canonical module directories.',
    '- `event` evidence is runtime-backed from explicit Domain event catalogues; `contract` evidence is runtime-backed from canonical module cross-domain contract declarations.',
    '- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.',
    '- `runtime-verified` here means structurally backed by canonical runtime registries for every critical step. It does not mean COS observed an end-to-end production trace. Observed execution evidence belongs to a later runtime-tracing layer.',
    '- `ProcessDiagram` renders core flow and ownership projections from the same registry definition. Sequence and entity lifecycle diagrams are not inferred from generic steps because the registry does not yet carry those semantics.',
    '- Coverage ratios expose documentation/evidence completeness; they are not business performance KPIs.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(loadDefinitions());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated business process reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  const current = fs.readFileSync(output, 'utf8');
  if (current !== rendered) {
    console.error('Generated business process reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Business Process Registry reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
