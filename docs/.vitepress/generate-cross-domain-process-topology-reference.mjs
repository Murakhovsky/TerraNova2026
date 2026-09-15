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
    'title: Cross-Domain Process Topology',
    'description: Generated topology of canonical business-process hops across COS Domain boundaries, including required contracts, target capabilities and evidence strength.',
    'status: generated',
    'updated: 2026-09-15',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Cross-Domain Process Topology',
    '',
    'Generated from Process Registry schema v5+ cross-domain steps and the current-checkout runtime evidence catalogue. Do not edit this page manually.',
    '',
    'This view answers **where a business process leaves its owning Domain, which canonical contract authorizes that hop, which target capability is used, and how the contract is evidenced**.',
    '',
    '## Summary',
    '',
    `- **Canonical processes scanned:** ${topology.processCount}`,
    `- **Cross-domain processes:** ${topology.crossDomainProcessCount}`,
    `- **Cross-domain steps:** ${topology.crossDomainStepCount}`,
    `- **Unique boundaries:** ${topology.boundaryCount}`,
    `- **Participating Domains:** ${topology.domains.length}`,
    '',
    '## Domain topology',
    '',
  ];

  if (topology.boundaries.length === 0) {
    lines.push('No canonical cross-domain process hops are currently declared.', '');
  } else {
    lines.push('```mermaid', 'flowchart LR');
    for (const domain of topology.domains) {
      lines.push(`    ${nodeId(domain)}["${domain}"]`);
    }
    for (const boundary of topology.boundaries) {
      const label = `${shortContract(boundary.contract)} · ${boundary.capability ?? 'capability gap'} · ${boundary.stepCount} step${boundary.stepCount === 1 ? '' : 's'}`;
      lines.push(`    ${nodeId(boundary.fromDomain)} -->|${label}| ${nodeId(boundary.toDomain)}`);
    }
    lines.push('```', '');
  }

  lines.push(
    '## Process hops',
    '',
    '| Process | Step | From | To | Contract | Target capability | Evidence |',
    '| --- | --- | --- | --- | --- | --- | --- |',
  );

  if (topology.hops.length === 0) {
    lines.push('| — | — | — | — | — | — | — |');
  } else {
    for (const hop of topology.hops) {
      lines.push(`| [${escapeCell(hop.processTitle)}](../${hop.workflow}) | \`${escapeCell(hop.stepId)}\` · ${escapeCell(hop.stepLabel)} | \`${hop.processDomain}\` | \`${hop.targetDomain}\` | \`${escapeCell(hop.contract)}\` | ${hop.capability ? `\`${hop.capability}\`` : 'gap'} | \`${hop.evidenceStrength}\` |`);
    }
  }

  lines.push('', '## Boundary aggregation', '');
  if (topology.boundaries.length === 0) {
    lines.push('No boundaries to aggregate.', '');
  } else {
    lines.push('| Boundary | Contract | Capability | Processes | Steps | Evidence |', '| --- | --- | --- | ---: | ---: | --- |');
    for (const boundary of topology.boundaries) {
      lines.push(`| \`${boundary.fromDomain} → ${boundary.toDomain}\` | \`${escapeCell(boundary.contract)}\` | ${boundary.capability ? `\`${boundary.capability}\`` : 'gap'} | ${boundary.processCount} | ${boundary.stepCount} | \`${boundary.evidenceStrength}\` |`);
    }
    lines.push('');
  }

  lines.push(
    '## Authority and limitations',
    '',
    '- Process Registry owns process topology and the Domain assigned to each step.',
    '- Module `cross_domain_contracts` declarations own synchronous Domain-boundary authority.',
    '- Runtime Evidence Resolver verifies that a process-owner Domain declares a `requires` contract toward the target Domain.',
    '- Target capability remains owned by the target Domain; a cross-domain hop never creates shared state ownership.',
    '- This reference aggregates canonical modeled hops only. It does not infer hidden dependencies from SQL, imports, service locators or HTTP calls.',
    '- Evidence strength describes structural current-checkout evidence. It is not an observed production execution trace.',
    '- The interactive documentation view is a projection of Process Registry semantics. This generated page is the evidence-enriched reference.',
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
