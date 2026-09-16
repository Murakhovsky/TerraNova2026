import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildDomainProcessCoverage } from './domain-process-coverage.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const output = path.join(docsRoot, '12-reference', 'domain-process-coverage.md');
const checkOnly = process.argv.includes('--check');

function render(coverage) {
  const lines = [
    '---',
    'title: Покриття доменів бізнес-процесами',
    'description: Згенероване покриття installable COS Domains канонічними моделями Process Registry.',
    'status: generated',
    'updated: 2026-09-16',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Покриття доменів бізнес-процесами',
    '',
    'Згенеровано з installable `app/Domains/*/module.php`, definitions Process Registry і явних process-coverage exemptions. Не редагуйте цю сторінку вручну.',
    '',
    'Цей довідник відповідає на навмисно незручне питання: чи кожний installable business Domain має щонайменше одну канонічну process model, або явно пояснив, чому її немає?',
    '',
    '## Підсумок',
    '',
    `- **Installable Domains:** ${coverage.installableDomains}`,
    `- **Покрито канонічним процесом:** ${coverage.coveredDomains}`,
    `- **Явних exemptions:** ${coverage.exemptDomains}`,
    `- **Без покриття:** ${coverage.missingDomains}`,
    '',
    '| Domain | Версія | Статус | Процесів | Кроків | Capability mapped | Capability gaps | Debt |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |',
  ];

  for (const domain of Object.values(coverage.domains)) {
    lines.push(`| \`${domain.id}\` · ${domain.name} | \`${domain.version}\` | \`${domain.status}\` | ${domain.processCount} | ${domain.stepCount} | ${domain.capabilityMappedSteps}/${domain.stepCount} | ${domain.capabilityGapSteps} | ${domain.debtItems} |`);
  }

  lines.push('', '## Канонічне ownership процесів', '');
  for (const domain of Object.values(coverage.domains)) {
    lines.push(`### ${domain.name} (\`${domain.id}\`)`, '');
    if (domain.processIds.length > 0) {
      for (const processId of domain.processIds) lines.push(`- \`${processId}\``);
    } else if (domain.exemption) {
      lines.push(`- **Exempt:** ${domain.exemption.reason}`);
      lines.push(`- **Owner:** ${domain.exemption.owner}`);
    } else {
      lines.push('- **Відсутнє покриття:** немає канонічного process і немає explicit exemption.');
    }
    lines.push('');
  }

  lines.push(
    '## Контракт покриття',
    '',
    '1. Для цього gate installable Domains є лише directories з канонічним `module.php`.',
    '2. Кожний installable Domain має володіти щонайменше однією Process Registry definition або мати один explicit exemption.',
    '3. Кожний `domain` у Process Registry має резолвитися до installable module manifest.',
    '4. Exemption стає невалідним, щойно Domain отримує канонічний process.',
    '5. Supporting Domain directories без `module.php` не підвищуються документацією до installable Domains мовчки.',
    '',
    '## Exemptions',
    '',
  );

  if (coverage.exemptDomains === 0) {
    lines.push('Активних exemptions немає.', '');
  } else {
    lines.push('| Domain | Owner | Причина |', '| --- | --- | --- |');
    for (const domain of Object.values(coverage.domains).filter((candidate) => candidate.status === 'exempt')) {
      lines.push(`| \`${domain.id}\` | ${domain.exemption.owner} | ${domain.exemption.reason} |`);
    }
    lines.push('');
  }

  lines.push(
    '## Авторитетність і обмеження',
    '',
    '- Module manifests визначають, які Domains є installable.',
    '- Process Registry визначає канонічне ownership процесів.',
    '- `process-coverage-exemptions.json` зберігає лише явні architecture exceptions.',
    '- Domain directories без `module.php` залишаються supporting/non-installable areas і не є помилками coverage.',
    '- Coverage означає наявність канонічної process model; воно не стверджує, що модель повна, автоматизована або runtime-verified.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(buildDomainProcessCoverage());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated Domain Process Coverage reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  if (fs.readFileSync(output, 'utf8') !== rendered) {
    console.error('Generated Domain Process Coverage reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Domain Process Coverage reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
