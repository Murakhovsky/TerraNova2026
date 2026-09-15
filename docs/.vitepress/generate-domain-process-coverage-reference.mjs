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
    'title: Domain Process Coverage',
    'description: Generated coverage of installable COS Domains by canonical Process Registry models.',
    'status: generated',
    'updated: 2026-09-15',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Domain Process Coverage',
    '',
    'Generated from installable `app/Domains/*/module.php`, Process Registry definitions and explicit process-coverage exemptions. Do not edit this page manually.',
    '',
    'This reference answers a deliberately uncomfortable question: does every installable business Domain have at least one canonical process model, or has it explicitly justified why it does not?',
    '',
    '## Summary',
    '',
    `- **Installable Domains:** ${coverage.installableDomains}`,
    `- **Covered by canonical process:** ${coverage.coveredDomains}`,
    `- **Explicit exemptions:** ${coverage.exemptDomains}`,
    `- **Missing coverage:** ${coverage.missingDomains}`,
    '',
    '| Domain | Version | Status | Processes | Steps | Capability mapped | Capability gaps | Debt |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |',
  ];

  for (const domain of Object.values(coverage.domains)) {
    lines.push(`| \`${domain.id}\` · ${domain.name} | \`${domain.version}\` | \`${domain.status}\` | ${domain.processCount} | ${domain.stepCount} | ${domain.capabilityMappedSteps}/${domain.stepCount} | ${domain.capabilityGapSteps} | ${domain.debtItems} |`);
  }

  lines.push('', '## Canonical process ownership', '');
  for (const domain of Object.values(coverage.domains)) {
    lines.push(`### ${domain.name} (\`${domain.id}\`)`, '');
    if (domain.processIds.length > 0) {
      for (const processId of domain.processIds) lines.push(`- \`${processId}\``);
    } else if (domain.exemption) {
      lines.push(`- **Exempt:** ${domain.exemption.reason}`);
      lines.push(`- **Owner:** ${domain.exemption.owner}`);
    } else {
      lines.push('- **Missing:** no canonical process and no explicit exemption.');
    }
    lines.push('');
  }

  lines.push(
    '## Coverage contract',
    '',
    '1. Only directories with a canonical `module.php` are installable Domains for this gate.',
    '2. Every installable Domain must own at least one Process Registry definition or have one explicit exemption.',
    '3. Every Process Registry `domain` must resolve to an installable module manifest.',
    '4. An exemption is invalid once the Domain gains a canonical process.',
    '5. Supporting Domain directories without `module.php` are not silently promoted to installable Domains by documentation.',
    '',
    '## Exemptions',
    '',
  );

  if (coverage.exemptDomains === 0) {
    lines.push('No active exemptions.', '');
  } else {
    lines.push('| Domain | Owner | Reason |', '| --- | --- | --- |');
    for (const domain of Object.values(coverage.domains).filter((candidate) => candidate.status === 'exempt')) {
      lines.push(`| \`${domain.id}\` | ${domain.exemption.owner} | ${domain.exemption.reason} |`);
    }
    lines.push('');
  }

  lines.push(
    '## Authority and limitations',
    '',
    '- Module manifests define which Domains are installable.',
    '- Process Registry defines canonical process ownership.',
    '- `process-coverage-exemptions.json` records only explicit architecture exceptions.',
    '- Domain directories without `module.php` remain supporting/non-installable areas and are not coverage failures.',
    '- Coverage means a Domain has a canonical process model; it does not claim the model is complete, automated or runtime-verified.',
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
