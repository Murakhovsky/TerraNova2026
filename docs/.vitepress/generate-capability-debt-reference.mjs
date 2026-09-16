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
  const definitions = new Map();
  for (const definition of loadProcessDefinitions()) definitions.set(definition.id, definition);
  return definitions;
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
    'title: Беклог боргу можливостей',
    'description: Згенерований backlog невирішених прогалин vocabulary capabilities, пов’язаних із канонічними кроками бізнес-процесів COS.',
    'status: generated',
    'updated: 2026-09-16',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Беклог боргу можливостей',
    '',
    'Згенеровано з `docs/.vitepress/capability-debt.json`, кроків Process Registry із capability gaps і module capability authority поточного checkout. Не редагуйте цю сторінку вручну.',
    '',
    'Capability debt означає, що бізнес-крок реальний, але Domain-власник ще не експонує достатньо семантичну discoverable capability. Це **не** означає відсутність runtime implementation.',
    '',
    '## Підсумок',
    '',
    `- **Відкритих debt items:** ${items.length}`,
    `- **High severity:** ${items.filter((item) => item.severity === 'high').length}`,
    `- **Medium severity:** ${items.filter((item) => item.severity === 'medium').length}`,
    `- **Зачеплених Domains:** ${domainSummary.size}`,
    '',
    '| Domain | Відкрито | High | Medium | Low |',
    '| --- | ---: | ---: | ---: | ---: |',
  ];

  for (const domain of [...domainSummary.keys()].sort()) {
    const summary = domainSummary.get(domain);
    lines.push(`| \`${domain}\` | ${summary.open} | ${summary.high} | ${summary.medium} | ${summary.low} |`);
  }

  lines.push(
    '',
    '## Пріоритетний backlog',
    '',
    '| Severity | Domain | Процес / крок | Runtime evidence | Target capability | Resolution |',
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
    '## Контракт закриття боргу',
    '',
    'Debt item вважається закритим лише коли виконані всі умови:',
    '',
    '1. Domain-власник декларує target capability у канонічному module `contributions.capabilities`.',
    '2. Відповідний крок Process Registry замінює `capability: null` + `capability_gap` на цю задекларовану capability.',
    '3. Відповідний item видалено з `capability-debt.json`.',
    '4. Генерація документації та перевірки проходять з того самого checkout.',
    '',
    '`check-capability-debt.mjs` забезпечує зв’язок 1:1 між Process Registry gaps і debt items. Він також відхиляє stale debt, якщо target capability вже існує, неправильний Domain ownership, невалідну severity або target name поза namespace Domain-власника.',
    '',
    '## Політика severity',
    '',
    '- `high` — capability gap прив’язаний до критичного кроку процесу.',
    '- `medium` — gap прив’язаний до некритичного, але канонічного кроку процесу.',
    '- `low` — зарезервовано для майбутніх неканонічних/optional класів debt; поточний workflow debt його не використовує.',
    '',
    'Severity описує architecture-model debt, а не severity operational incident.',
    '',
    '## Авторитетність і обмеження',
    '',
    '- Process Registry володіє фактом існування capability gap.',
    '- Capability Debt Registry володіє remediation metadata для цієї прогалини.',
    '- Domain module manifests залишаються authority для capabilities, які реально існують.',
    '- Runtime Evidence Resolver залишається authority для source/runtime verification.',
    '- Generated Markdown є лише projection і ніколи не використовується як executable authority.',
    '- Закриття debt може вимагати Domain patch release; documentation layer не мутує Domain manifests мовчки.',
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
