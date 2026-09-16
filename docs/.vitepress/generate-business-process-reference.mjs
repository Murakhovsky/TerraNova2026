import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  loadRuntimeEvidence,
  processVerification,
  resolveRuntimeMapping,
} from './process-runtime-evidence.mjs';
import { loadProcessDefinitions } from './process-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const output = path.join(docsRoot, '12-reference/business-processes.md');
const checkOnly = process.argv.includes('--check');
const catalogue = loadRuntimeEvidence();

function loadDefinitions() {
  return loadProcessDefinitions()
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

function capabilityLabel(step) {
  if (typeof step.capability === 'string' && step.capability !== '') {
    return `\`${step.capability}\``;
  }
  return `gap: \`${step.capability_gap ?? 'missing'}\``;
}

function coverage(definition) {
  const verification = processVerification(definition, catalogue);
  const steps = definition.steps ?? [];
  return {
    ...verification,
    owned: steps.filter((step) => typeof step.owner === 'string' && step.owner !== '').length,
    capabilityMapped: steps.filter((step) => typeof step.capability === 'string' && step.capability !== '').length,
    capabilityGaps: steps.filter((step) => step.capability === null).length,
    crossDomain: steps.filter((step) => step.domain && step.domain !== definition.domain).length,
  };
}

function render(definitions) {
  const lines = [
    '---',
    'title: Реєстр бізнес-процесів',
    'description: Згенерований реєстр канонічних бізнес-процесів COS, ownership, покриття capabilities і runtime verification на основі evidence.',
    'status: generated',
    'updated: 2026-09-16',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Реєстр бізнес-процесів',
    '',
    'Згенеровано з `resources/processes/*.json`, канонічних module capabilities і каталогу runtime evidence поточного checkout. Не редагуйте цю сторінку вручну.',
    '',
    'Бізнес-стан, покриття capabilities і runtime verification є окремими вимірами: крок може виконуватися поточним кодом, навіть якщо vocabulary можливостей його Domain ще неповний.',
    '',
    '## Індекс процесів',
    '',
    '| Процес | Domain | Бізнес-стан | Verification | Кроків | Cross-domain | Ownership | Capability mapped | Evidence verified | Critical source | Critical runtime | Workflow |',
    '| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |',
  ];

  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | \`${definition.domain}\` | \`${definition.state}\` | \`${stats.level}\` | ${stats.steps} | ${stats.crossDomain} | ${stats.owned}/${stats.steps} | ${stats.capabilityMapped}/${stats.steps} | ${stats.verifiedSteps}/${stats.steps} | ${stats.criticalSourceVerified}/${stats.critical} | ${stats.criticalRuntimeVerified}/${stats.critical} | [Відкрити workflow](${workflowLink(definition)}) |`);
  }

  lines.push('', '## Модель перевірки та можливостей', '');
  lines.push('- `capability mapped` — крок посилається на discoverable capability, задекларовану його Domain module і присутню в канонічному vocabulary capabilities Architecture Graph.');
  lines.push('- `capability gap` — крок реальний і може мати runtime evidence, але Domain-власник ще не декларує достатньо семантичну module capability для цієї бізнес-операції.');
  lines.push('- `cross-domain` — крок виконується в Domain, відмінному від власника процесу, і захищений перевіреним `requires` contract від Domain процесу до Domain кроку.');
  lines.push('- `documented` — topology реєстру існує, але щонайменше один критичний крок не підтверджений resolvable evidence поточного checkout.');
  lines.push('- `source-verified` — кожний критичний крок має щонайменше один mapping, який резолвиться до поточного source/code evidence.');
  lines.push('- `runtime-verified` — кожний критичний крок має щонайменше один mapping до канонічного runtime/contract registry. Це структурна перевірка, а не доказ спостереженого production execution trace.');
  lines.push('', '| Процес | Кроків з owner | Capability mapped | Capability gaps | Cross-domain кроки | Mapped кроки | Evidence-verified кроки | Runtime-backed кроки | Critical source-verified | Critical runtime-verified |', '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |');
  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push(`| ${escapeCell(definition.title)} | ${stats.owned}/${stats.steps} | ${stats.capabilityMapped}/${stats.steps} | ${stats.capabilityGaps}/${stats.steps} | ${stats.crossDomain}/${stats.steps} | ${stats.mappedSteps}/${stats.steps} | ${stats.verifiedSteps}/${stats.steps} | ${stats.runtimeVerifiedSteps}/${stats.steps} | ${stats.criticalSourceVerified}/${stats.critical} | ${stats.criticalRuntimeVerified}/${stats.critical} |`);
  }

  for (const definition of definitions) {
    const stats = coverage(definition);
    lines.push('', `## ${definition.title}`, '');
    lines.push(`- **Process ID:** \`${definition.id}\``);
    lines.push(`- **Schema:** \`v${definition.schema_version}\``);
    lines.push(`- **Domain:** \`${definition.domain}\``);
    lines.push(`- **Бізнес-стан:** \`${definition.state}\``);
    lines.push(`- **Покриття capabilities:** ${stats.capabilityMapped}/${stats.steps} кроків`);
    lines.push(`- **Cross-domain кроки:** ${stats.crossDomain}/${stats.steps}`);
    lines.push(`- **Derived verification:** \`${stats.level}\``);
    lines.push(`- **Тригер:** ${definition.trigger}`);
    lines.push(`- **Workflow:** [${definition.title}](${workflowLink(definition)})`);
    lines.push('', '**Результати**', '');
    for (const outcome of definition.outcomes) lines.push(`- ${outcome}`);
    lines.push('', '**Відповідальність, capabilities і runtime evidence**', '');
    lines.push('| Крок | Owner | Domain | Capability / gap | Вид | Критичний | Executable / evidence mapping |');
    lines.push('| --- | --- | --- | --- | --- | --- | --- |');
    for (const step of definition.steps) {
      const mappings = (step.runtime ?? []).map((mapping) => mappingLabel(mapping, definition)).join('<br>') || '—';
      lines.push(`| ${escapeCell(step.label)} | ${escapeCell(step.owner ?? '—')} | \`${step.domain ?? definition.domain}\` | ${capabilityLabel(step)} | \`${step.kind}\` | ${step.critical === true ? 'так' : 'ні'} | ${mappings} |`);
    }
  }

  lines.push(
    '',
    '## Авторитетність і обмеження',
    '',
    '- Registry schema `v5` розширює v4 contract-guarded cross-domain кроками. Наявні same-domain definitions v4 залишаються валідними.',
    '- Задекларована capability має резолвитися до vocabulary capabilities Domain кроку; відсутня semantic capability має бути явно представлена як `capability: null` + `capability_gap`.',
    '- Покриття capabilities не виводиться з назв класів, routes або permissions. Воно показує лише канонічні discoverable module capabilities.',
    '- Cross-domain крок легальний лише для schema v5+ і за наявності перевіреного `contract` mapping, чиє module evidence декларує `role: requires` від Domain процесу до Domain кроку.',
    '- Cross-domain виконання не створює shared state ownership: capability кроку належить foreign Domain, а процес залишається у власності root Domain.',
    '- Бізнес-стан (`as-is` / `to-be`) відділений від derived runtime verification.',
    '- Verification ніколи не задається вручну в process JSON. Вона обчислюється з mappings, перевірених через `generate-runtime-evidence.php`, і точних source symbols поточного checkout.',
    '- Evidence типів `use_case` і `command` підтверджується source з канонічних module directories.',
    '- Evidence типу `event` підтверджується runtime з explicit Domain event catalogues; `contract` — з канонічних module cross-domain contract declarations.',
    '- `source` mappings мають резолвитися до наявного repository file і, якщо symbol заданий, містити оголошений symbol.',
    '- `runtime-verified` тут означає структурне підтвердження canonical runtime registries для кожного критичного кроку. Це не означає, що COS спостерігав end-to-end production trace.',
    '- `ProcessDiagram` рендерить core flow, ownership, capability і Domain projections з того самого registry definition.',
    '- Coverage ratios показують повноту architecture/documentation, а не business performance KPI.',
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
