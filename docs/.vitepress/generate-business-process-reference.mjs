import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const registryRoot = path.join(here, 'processes');
const output = path.join(docsRoot, '12-reference/business-processes.md');
const checkOnly = process.argv.includes('--check');

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

function mappingLabel(mapping) {
  if (mapping.type === 'source') {
    return `source \`${mapping.path}\`${mapping.symbol ? ` · \`${mapping.symbol}\`` : ''}`;
  }
  return `${mapping.type} \`${mapping.ref}\``;
}

function coverage(definition) {
  const steps = definition.steps ?? [];
  const critical = steps.filter((step) => step.critical === true);
  return {
    steps: steps.length,
    owned: steps.filter((step) => typeof step.owner === 'string' && step.owner !== '').length,
    runtimeMapped: steps.filter((step) => Array.isArray(step.runtime) && step.runtime.length > 0).length,
    critical: critical.length,
    criticalMapped: critical.filter((step) => Array.isArray(step.runtime) && step.runtime.length > 0).length,
  };
}

function render(definitions) {
  const lines = [
    '---',
    'title: Business Process Registry',
    'description: Generated registry of canonical COS business processes, ownership and runtime coverage.',
    'status: generated',
    'updated: 2026-09-15',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Business Process Registry',
    '',
    'Generated from `docs/.vitepress/processes/*.json`. Do not edit this page manually.',
    '',
    'The registry connects human workflow documentation to process ownership and executable COS references without pretending that every business step is automated.',
    '',
    '## Process index',
    '',
    '| Process | Domain | Truth state | Steps | Ownership | Runtime mapped | Critical mapped | Workflow |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | --- |',
  ];

  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | \`${definition.domain}\` | \`${definition.state}\` | ${stats.steps} | ${stats.owned}/${stats.steps} | ${stats.runtimeMapped}/${stats.steps} | ${stats.criticalMapped}/${stats.critical} | [Open workflow](${workflowLink(definition)}) |`);
  }

  lines.push('', '## Coverage', '');
  lines.push('Coverage is structural, not a quality score. `owned` means a responsible actor is declared; `runtime mapped` means at least one executable/reference mapping exists; `critical mapped` is the minimum requirement for `runtime-verified`.');
  lines.push('', '| Process | Owned steps | Runtime-mapped steps | Runtime-mapped critical steps |', '| --- | ---: | ---: | ---: |');
  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | ${stats.owned}/${stats.steps} | ${stats.runtimeMapped}/${stats.steps} | ${stats.criticalMapped}/${stats.critical} |`);
  }

  for (const definition of definitions) {
    lines.push('', `## ${definition.title}`, '');
    lines.push(`- **Process ID:** \`${definition.id}\``);
    lines.push(`- **Schema:** \`v${definition.schema_version}\``);
    lines.push(`- **Domain:** \`${definition.domain}\``);
    lines.push(`- **Truth state:** \`${definition.state}\``);
    lines.push(`- **Trigger:** ${definition.trigger}`);
    lines.push(`- **Workflow:** [${definition.title}](${workflowLink(definition)})`);
    lines.push('', '**Outcomes**', '');
    for (const outcome of definition.outcomes) lines.push(`- ${outcome}`);
    lines.push('', '**Ownership and runtime mapping**', '');
    lines.push('| Step | Owner | Kind | Critical | Executable / reference mapping |');
    lines.push('| --- | --- | --- | --- | --- |');
    for (const step of definition.steps) {
      const mappings = (step.runtime ?? []).map(mappingLabel).join('<br>') || '—';
      lines.push(`| ${escapeCell(step.label)} | ${escapeCell(step.owner ?? '—')} | \`${step.kind}\` | ${step.critical === true ? 'yes' : 'no'} | ${mappings} |`);
    }
  }

  lines.push(
    '',
    '## Authority and limitations',
    '',
    '- Registry schema `v2` requires every process step to declare exactly one responsible `owner` from the process `actors` list.',
    '- Registry structure, topology, ownership and mappings are machine-checked by `check-processes.mjs`.',
    '- `use_case`, `command` and `event` mappings must resolve to generated reference from the same checkout.',
    '- `source` mappings must resolve to an existing repository file and, when provided, contain the declared symbol.',
    '- `as-is` means the process is real, not that every step is machine-enforced.',
    '- `runtime-verified` requires every critical step to have an explicit runtime mapping.',
    '- `ProcessDiagram` renders both core flow and ownership projection from the same registry definition.',
    '- Coverage ratios expose documentation completeness; they are not business performance KPIs.',
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
