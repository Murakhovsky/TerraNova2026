import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildCrossDomainProcessTopology } from './cross-domain-process-topology.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const output = path.join(docsRoot, '12-reference', 'cross-domain-process-topology.md');
const checkOnly = process.argv.includes('--check');

function escapeCell(value) {
  return String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
}

function nodeId(domain) {
  return `domain_${String(domain).replace(/[^A-Za-z0-9_]/g, '_')}`;
}

function shortContract(ref) {
  return String(ref).split('\\').filter(Boolean).at(-1) ?? String(ref);
}

function render(topology) {
  const lines = [
    '---',
    'title: Міждоменна топологія бізнес-процесів',
    'description: Згенерована topology канонічних переходів бізнес-процесів через межі COS Domains, включно з required contracts, target capabilities та evidence strength.',
    'status: generated',
    'updated: 2026-09-16',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Міждоменна топологія бізнес-процесів',
    '',
    'Згенеровано з cross-domain кроків Process Registry schema v5+ і каталогу runtime evidence поточного checkout. Не редагуйте цю сторінку вручну.',
    '',
    'Це представлення показує, **де бізнес-процес залишає свій Domain-власник, який канонічний contract дозволяє цей перехід, яка target capability використовується і як contract підтверджено evidence**.',
    '',
    '## Підсумок',
    '',
    `- **Перевірено канонічних процесів:** ${topology.processCount}`,
    `- **Cross-domain процесів:** ${topology.crossDomainProcessCount}`,
    `- **Cross-domain кроків:** ${topology.crossDomainStepCount}`,
    `- **Унікальних меж:** ${topology.boundaryCount}`,
    `- **Domains-учасників:** ${topology.domains.length}`,
    '',
    '## Топологія доменів',
    '',
  ];

  if (topology.boundaries.length === 0) {
    lines.push('Канонічних cross-domain переходів процесів наразі не задекларовано.', '');
  } else {
    lines.push('```mermaid', 'flowchart LR');
    for (const domain of topology.domains) {
      lines.push(`    ${nodeId(domain)}["${domain}"]`);
    }
    for (const boundary of topology.boundaries) {
      const label = `${shortContract(boundary.contract)} · ${boundary.capability ?? 'прогалина capability'} · ${boundary.stepCount} ${boundary.stepCount === 1 ? 'крок' : 'кроків'}`;
      lines.push(`    ${nodeId(boundary.fromDomain)} -->|${label}| ${nodeId(boundary.toDomain)}`);
    }
    lines.push('```', '');
  }

  lines.push(
    '## Переходи процесів',
    '',
    '| Процес | Крок | З Domain | До Domain | Contract | Target capability | Evidence |',
    '| --- | --- | --- | --- | --- | --- | --- |',
  );

  if (topology.hops.length === 0) {
    lines.push('| — | — | — | — | — | — | — |');
  } else {
    for (const hop of topology.hops) {
      lines.push(`| [${escapeCell(hop.processTitle)}](../${hop.workflow}) | \`${escapeCell(hop.stepId)}\` · ${escapeCell(hop.stepLabel)} | \`${hop.processDomain}\` | \`${hop.targetDomain}\` | \`${escapeCell(hop.contract)}\` | ${hop.capability ? `\`${hop.capability}\`` : 'gap'} | \`${hop.evidenceStrength}\` |`);
    }
  }

  lines.push('', '## Агрегація меж', '');
  if (topology.boundaries.length === 0) {
    lines.push('Немає меж для агрегації.', '');
  } else {
    lines.push('| Межа | Contract | Capability | Процесів | Кроків | Evidence |', '| --- | --- | --- | ---: | ---: | --- |');
    for (const boundary of topology.boundaries) {
      lines.push(`| \`${boundary.fromDomain} → ${boundary.toDomain}\` | \`${escapeCell(boundary.contract)}\` | ${boundary.capability ? `\`${boundary.capability}\`` : 'gap'} | ${boundary.processCount} | ${boundary.stepCount} | \`${boundary.evidenceStrength}\` |`);
    }
    lines.push('');
  }

  lines.push(
    '## Авторитетність і обмеження',
    '',
    '- Process Registry володіє topology процесу і Domain, призначеним кожному кроку.',
    '- Декларації module `cross_domain_contracts` володіють authority синхронних Domain boundaries.',
    '- Runtime Evidence Resolver перевіряє, що Domain-власник процесу декларує `requires` contract до target Domain.',
    '- Target capability залишається у власності target Domain; cross-domain перехід ніколи не створює shared state ownership.',
    '- Цей довідник агрегує лише канонічні змодельовані переходи. Він не виводить hidden dependencies із SQL, imports, service locators або HTTP calls.',
    '- Evidence strength описує структурне evidence поточного checkout. Це не observed production execution trace.',
    '- Interactive documentation view є projection семантики Process Registry. Ця generated page є evidence-enriched reference.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(buildCrossDomainProcessTopology());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated Cross-Domain Process Topology reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  if (fs.readFileSync(output, 'utf8') !== rendered) {
    console.error('Generated Cross-Domain Process Topology reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Cross-Domain Process Topology reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
