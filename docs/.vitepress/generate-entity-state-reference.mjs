import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { buildEntityStateRegistry } from './entity-state-registry.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const docsRoot = path.resolve(here, '..');
const output = path.join(docsRoot, '12-reference', 'entity-states.md');
const checkOnly = process.argv.includes('--check');

function values(values) {
  return (values ?? []).map((value) => `\`${value}\``).join(', ') || '—';
}

function lifecycleNotes(state) {
  const notes = [];
  if ((state.terminal_values ?? []).length > 0) notes.push(`terminal: ${values(state.terminal_values)}`);
  if ((state.closed_values ?? []).length > 0) notes.push(`closed: ${values(state.closed_values)}`);
  return notes.join('<br>') || '—';
}

function render(registry) {
  const lines = [
    '---',
    'title: Entity & State Registry',
    'description: Canonical COS business entities, ownership boundaries and source-verified state vocabularies.',
    'status: generated',
    'updated: 2026-09-17',
    'kind: reference',
    'contract: reference-v1',
    'generated: true',
    '---',
    '',
    '# Entity & State Registry',
    '',
    `> Structured registry schema v${registry.schemaVersion}. Business facts were reviewed against main revision \`${registry.reviewedMainRevision}\`; this generated projection is verified against the current documentation checkout.`,
    '',
    'This registry names only canonical business entities and aggregate-like persisted concepts. A PHP class is not promoted to a business entity merely because it exists.',
    '',
    '## Summary',
    '',
    `- **Canonical entities:** ${registry.totalEntities}`,
    `- **Explicit state models:** ${registry.stateModels}`,
    `- **Installable Domains represented:** ${Object.keys(registry.domains).length}`,
    `- **Canonical process touchpoints:** ${registry.processTouchpoints}`,
    '',
    '| Domain | Entities | State models | Process touchpoints |',
    '| --- | ---: | ---: | ---: |',
  ];

  for (const [domain, stats] of Object.entries(registry.domains).sort(([a], [b]) => a.localeCompare(b))) {
    lines.push(`| \`${domain}\` | ${stats.entities} | ${stats.stateModels} | ${stats.processTouchpoints} |`);
  }

  lines.push(
    '',
    '## Canonical entities and states',
    '',
    '| Entity | Domain | Representation | Identity boundary | State semantics | States | Terminal / closed | Process touchpoints | Source |',
    '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
  );

  for (const entity of registry.entities) {
    const steps = entity.process_steps.map((step) => `\`${step}\``).join('<br>');
    const source = `\`${entity.source.path}\``;
    lines.push(`| **${entity.name}**<br>\`${entity.id}\` | \`${entity.domain}\` | \`${entity.representation}\` | ${entity.identity} | \`${entity.state.semantics}\`<br>${entity.state.name} | ${values(entity.state.values)} | ${lifecycleNotes(entity.state)} | ${steps} | ${source} |`);
  }

  lines.push(
    '',
    '## Entity boundaries',
    '',
    '### Property',
    '',
    '```text',
    'Property Asset ≠ Inventory Item ≠ Listing ≠ Publication',
    'physical lifecycle ≠ commercial state ≠ market presentation ≠ channel state',
    '```',
    '',
    '`sold` and `rented` are Inventory states. They do not erase or terminate the physical Property Asset. A Listing or Publication may be hidden, expired or archived without changing the identity or physical lifecycle of the Property Asset.',
    '',
    '### Sales',
    '',
    '```text',
    'Lead ≠ Client Case / Deal ≠ Property Match',
    'inbound demand signal ≠ managed sales process ≠ demand↔supply association',
    '```',
    '',
    'Sales currently represents some canonical entities through application/persistence services instead of one dedicated aggregate class. `application-persisted` is an explicit representation fact, not a downgrade of business ownership.',
    '',
    '### Diagnostic',
    '',
    '`DiagnosticPack` is versioned: revision creates a new draft version instead of mutating a published version. `DiagnosticSession` pins an exact pack version and has its own independent lifecycle.',
    '',
    '## State contract',
    '',
    '1. Entity identity and entity state are separate concepts.',
    '2. State vocabularies are scoped to their owning entity and Domain; COS has no universal business `status` enum.',
    '3. Registry state values must match the current source enum or public string constants exactly; CI fails on drift.',
    '4. `terminal_values` are declared only when source semantics explicitly prohibit further lifecycle progression.',
    '5. `closed_values` describe business closure semantics without claiming the state is technically irreversible.',
    '6. Process touchpoints must resolve to canonical Process Registry steps owned by the same Domain as the entity.',
    '',
    '## Authority and freshness',
    '',
    '- Runtime/domain code on `main` remains the factual authority.',
    `- This registry records the main revision last reviewed for these facts: \`${registry.reviewedMainRevision}\`.`,
    '- Documentation CI verifies the structured registry and generated projection against the current documentation checkout; it does not silently claim cross-branch freshness.',
    '- Automated inter-branch freshness enforcement belongs to documentation governance and must be explicit rather than inferred from a green build.',
    '- Generated Markdown is a projection, never executable authority.',
    '',
  );

  return lines.join('\n');
}

const rendered = render(buildEntityStateRegistry());

if (checkOnly) {
  if (!fs.existsSync(output)) {
    console.error(`Generated Entity & State Registry reference is missing: ${path.relative(process.cwd(), output)}`);
    process.exit(1);
  }
  if (fs.readFileSync(output, 'utf8') !== rendered) {
    console.error('Generated Entity & State Registry reference is stale. Run npm run docs:generate.');
    process.exit(1);
  }
  console.log('Entity & State Registry reference is current.');
  process.exit(0);
}

fs.writeFileSync(output, rendered, 'utf8');
console.log(`Generated ${path.relative(process.cwd(), output)}.`);
